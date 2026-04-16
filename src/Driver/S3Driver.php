<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Driver;

use MonkeysLegion\Files\Contracts\CloudStorageInterface;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * AWS S3 / S3-compatible (MinIO, DigitalOcean Spaces, Backblaze B2)
 * storage driver with lazy client initialization, presigned uploads,
 * and multipart support.
 *
 * Requires `aws/aws-sdk-php ^3.300` (suggest dependency).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class S3Driver implements CloudStorageInterface
{
    private ?\Aws\S3\S3Client $client = null;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region = 'us-east-1',
        private readonly ?string $endpoint = null,
        private readonly ?string $accessKey = null,
        private readonly ?string $secretKey = null,
        private readonly string $prefix = '',
        private readonly Visibility $defaultVisibility = Visibility::Private,
        /** @var array<string, mixed> */
        private readonly array $options = [],
    ) {}

    // ── Write Operations ─────────────────────────────────────────

    public function put(string $path, string $contents, array $options = []): bool
    {
        $acl = ($options['visibility'] ?? $this->defaultVisibility) === Visibility::Public
            ? 'public-read' : 'private';

        $this->getClient()->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $this->prefixedPath($path),
            'Body'        => $contents,
            'ACL'         => $acl,
            'ContentType' => $options['content_type'] ?? 'application/octet-stream',
        ]);

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream provided');
        }

        $acl = ($options['visibility'] ?? $this->defaultVisibility) === Visibility::Public
            ? 'public-read' : 'private';

        $this->getClient()->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $this->prefixedPath($path),
            'Body'        => $stream,
            'ACL'         => $acl,
            'ContentType' => $options['content_type'] ?? 'application/octet-stream',
        ]);

        return true;
    }

    public function append(string $path, string $contents): bool
    {
        $existing = $this->get($path) ?? '';

        return $this->put($path, $existing . $contents);
    }

    public function prepend(string $path, string $contents): bool
    {
        $existing = $this->get($path) ?? '';

        return $this->put($path, $contents . $existing);
    }

    // ── Read Operations ──────────────────────────────────────────

    public function get(string $path): ?string
    {
        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            return (string) $result['Body'];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw new StorageException("S3 get failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getStream(string $path): mixed
    {
        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            return $result['Body']->detach();
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw new StorageException("S3 getStream failed: {$e->getMessage()}", 0, $e);
        }
    }

    // ── Delete Operations ────────────────────────────────────────

    public function delete(string $path): bool
    {
        $this->getClient()->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $this->prefixedPath($path),
        ]);

        return true;
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function exists(string $path): bool
    {
        return $this->getClient()->doesObjectExistV2(
            $this->bucket,
            $this->prefixedPath($path),
        );
    }

    public function size(string $path): ?int
    {
        try {
            $result = $this->getClient()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            return (int) $result['ContentLength'];
        } catch (\Aws\S3\Exception\S3Exception) {
            return null;
        }
    }

    public function mimeType(string $path): ?string
    {
        try {
            $result = $this->getClient()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            return $result['ContentType'] ?? null;
        } catch (\Aws\S3\Exception\S3Exception) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $result = $this->getClient()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            $date = $result['LastModified'] ?? null;

            return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
        } catch (\Aws\S3\Exception\S3Exception) {
            return null;
        }
    }

    public function checksum(string $path, string $algo = 'sha256'): ?string
    {
        // S3 provides ETag (MD5 for non-multipart), but for sha256 we must download
        $contents = $this->get($path);

        return $contents !== null ? hash($algo, $contents) : null;
    }

    // ── Visibility ───────────────────────────────────────────────

    public function visibility(string $path): ?Visibility
    {
        try {
            $result = $this->getClient()->getObjectAcl([
                'Bucket' => $this->bucket,
                'Key'    => $this->prefixedPath($path),
            ]);

            foreach ($result['Grants'] ?? [] as $grant) {
                $grantee = $grant['Grantee']['URI'] ?? '';

                if (str_contains($grantee, 'AllUsers')) {
                    return Visibility::Public;
                }
            }

            return Visibility::Private;
        } catch (\Aws\S3\Exception\S3Exception) {
            return null;
        }
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $acl = $visibility === Visibility::Public ? 'public-read' : 'private';

        $this->getClient()->putObjectAcl([
            'Bucket' => $this->bucket,
            'Key'    => $this->prefixedPath($path),
            'ACL'    => $acl,
        ]);
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function copy(string $source, string $destination): bool
    {
        $this->getClient()->copyObject([
            'Bucket'     => $this->bucket,
            'CopySource' => $this->bucket . '/' . $this->prefixedPath($source),
            'Key'        => $this->prefixedPath($destination),
        ]);

        return true;
    }

    public function move(string $source, string $destination): bool
    {
        if ($this->copy($source, $destination)) {
            return $this->delete($source);
        }

        return false;
    }

    // ── Directory Operations ─────────────────────────────────────

    public function url(string $path): string
    {
        $baseUrl = $this->endpoint
            ?? "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";

        return rtrim($baseUrl, '/') . '/' . ltrim($this->prefixedPath($path), '/');
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $prefix = $this->prefixedPath($directory);

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $params = [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ];

        if (!$recursive) {
            $params['Delimiter'] = '/';
        }

        $result = $this->getClient()->listObjectsV2($params);
        $files  = [];

        foreach ($result['Contents'] ?? [] as $object) {
            $key = $object['Key'];

            // Skip the directory prefix itself
            if ($key === $prefix) {
                continue;
            }

            $files[] = $this->stripPrefix($key);
        }

        return $files;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $prefix = $this->prefixedPath($directory);

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $result = $this->getClient()->listObjectsV2([
            'Bucket'    => $this->bucket,
            'Prefix'    => $prefix,
            'Delimiter' => '/',
        ]);

        $dirs = [];

        foreach ($result['CommonPrefixes'] ?? [] as $prefixEntry) {
            $dirs[] = rtrim($this->stripPrefix($prefixEntry['Prefix']), '/');
        }

        return $dirs;
    }

    public function makeDirectory(string $path): bool
    {
        // S3 has no real directories — put an empty marker
        return $this->put(rtrim($path, '/') . '/', '');
    }

    public function deleteDirectory(string $path): bool
    {
        $prefix = $this->prefixedPath(rtrim($path, '/')) . '/';

        $objects = $this->getClient()->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => $prefix,
        ]);

        $keys = array_map(
            fn(array $o) => ['Key' => $o['Key']],
            $objects['Contents'] ?? [],
        );

        if ($keys === []) {
            return true;
        }

        $this->getClient()->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $keys],
        ]);

        return true;
    }

    // ── Cloud-Specific ───────────────────────────────────────────

    public function temporaryUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $cmd = $this->getClient()->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $this->prefixedPath($path),
        ]);

        $ttl = $expiration->getTimestamp() - time();

        $request = $this->getClient()->createPresignedRequest($cmd, "+{$ttl} seconds");

        return (string) $request->getUri();
    }

    public function presignedUploadUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $cmd = $this->getClient()->getCommand('PutObject', array_filter([
            'Bucket'      => $this->bucket,
            'Key'         => $this->prefixedPath($path),
            'ContentType' => $options['content_type'] ?? null,
        ]));

        $ttl = $expiration->getTimestamp() - time();

        $request = $this->getClient()->createPresignedRequest($cmd, "+{$ttl} seconds");

        return (string) $request->getUri();
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function getDriver(): string
    {
        return 's3';
    }

    // ── Internal ─────────────────────────────────────────────────

    /** Lazy-initialize the S3 client. */
    private function getClient(): \Aws\S3\S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new StorageException(
                'S3 driver requires aws/aws-sdk-php ^3.300. Run: composer require aws/aws-sdk-php',
            );
        }

        $config = [
            'version' => 'latest',
            'region'  => $this->region,
        ];

        if ($this->endpoint !== null) {
            $config['endpoint']                = $this->endpoint;
            $config['use_path_style_endpoint'] = $this->options['use_path_style'] ?? true;
        }

        if ($this->accessKey !== null && $this->secretKey !== null) {
            $config['credentials'] = [
                'key'    => $this->accessKey,
                'secret' => $this->secretKey,
            ];
        }

        $this->client = new \Aws\S3\S3Client($config);

        return $this->client;
    }

    private function prefixedPath(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->prefix !== '' ? rtrim($this->prefix, '/') . '/' . $path : $path;
    }

    private function stripPrefix(string $key): string
    {
        if ($this->prefix !== '' && str_starts_with($key, $this->prefix)) {
            return ltrim(substr($key, strlen(rtrim($this->prefix, '/'))), '/');
        }

        return $key;
    }
}
