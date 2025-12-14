<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Exception\FileNotFoundException;

/**
 * S3-compatible storage driver.
 */
final class S3Storage implements FileStorage
{
    private S3Client $client;

    public function __construct(
        private string $bucket,
        private string $region = 'us-east-1',
        private ?string $endpoint = null,
        private ?string $accessKey = null,
        private ?string $secretKey = null,
        private string $visibility = 'private',
        private array $options = [],
    ) {
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $config = [
            'version' => 'latest',
            'region' => $this->region,
        ];

        if ($this->accessKey && $this->secretKey) {
            $config['credentials'] = [
                'key' => $this->accessKey,
                'secret' => $this->secretKey,
            ];
        }

        if ($this->endpoint) {
            $config['endpoint'] = $this->endpoint;
            $config['use_path_style_endpoint'] = $this->options['path_style'] ?? true;
        }

        $this->client = new S3Client($config);
    }

    public function put(string $path, string $contents, array $options = []): bool
    {
        try {
            $this->client->putObject(array_merge([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
                'ACL' => $this->getAcl($options),
            ], $this->extractOptions($options)));
            
            return true;
        } catch (S3Exception $e) {
            throw new StorageException("Failed to write to S3: " . $e->getMessage(), 0, $e);
        }
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException("Invalid stream provided");
        }

        try {
            $this->client->putObject(array_merge([
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $stream,
                'ACL' => $this->getAcl($options),
            ], $this->extractOptions($options)));

            return true;
        } catch (S3Exception $e) {
            throw new StorageException("Failed to write stream to S3: " . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return (string) $result['Body'];
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw new StorageException("Failed to read from S3: " . $e->getMessage(), 0, $e);
        }
    }

    public function getStream(string $path): mixed
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return $result['Body']->detach();
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw new StorageException("Failed to get stream from S3: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function deleteMultiple(array $paths): bool
    {
        if (empty($paths)) {
            return true;
        }

        try {
            $objects = array_map(fn($path) => ['Key' => $path], $paths);

            $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects],
            ]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function exists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    public function size(string $path): ?int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return (int) $result['ContentLength'];
        } catch (S3Exception $e) {
            return null;
        }
    }

    public function mimeType(string $path): ?string
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return $result['ContentType'];
        } catch (S3Exception $e) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return $result['LastModified']->getTimestamp();
        } catch (S3Exception $e) {
            return null;
        }
    }

    public function copy(string $source, string $destination): bool
    {
        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => urlencode("{$this->bucket}/{$source}"),
                'Key' => $destination,
                'ACL' => $this->getAcl(),
            ]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function move(string $source, string $destination): bool
    {
        if ($this->copy($source, $destination)) {
            return $this->delete($source);
        }
        return false;
    }

    public function url(string $path): string
    {
        if ($this->visibility === 'public') {
            return $this->client->getObjectUrl($this->bucket, $path);
        }

        return $this->temporaryUrl($path, new \DateTimeImmutable('+1 hour'));
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $path,
        ]);

        $request = $this->client->createPresignedRequest($cmd, $expiration);

        return (string) $request->getUri();
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $results = [];
        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $directory ? rtrim($directory, '/') . '/' : '',
            'Delimiter' => $recursive ? '' : '/',
        ]);

        foreach ($paginator as $page) {
            // Files
            foreach ($page['Contents'] ?? [] as $object) {
                if (!$this->isDirectory($object['Key'])) {
                    $results[] = $object['Key'];
                }
            }
        }

        return $results;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $results = [];
        $prefix = $directory ? rtrim($directory, '/') . '/' : '';

        // If not recursive, use CommonPrefixes (folders)
        if (!$recursive) {
           $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'Delimiter' => '/',
            ]);
            
            foreach ($paginator as $page) {
                foreach ($page['CommonPrefixes'] ?? [] as $commonPrefix) {
                    $results[] = rtrim($commonPrefix['Prefix'], '/');
                }
            }
        } else {
            // Recursive scan logic for folders is complex in S3 as folders don't really exist
            // We'll skip for now or implementing basic prefix scan?
            // "Directories" in S3 are just prefixes.
        }

        return $results;
    }

    public function makeDirectory(string $path): bool
    {
        // S3 doesn't have directories, but we can simulate it by creating a 0-byte object with trailing slash
        $path = rtrim($path, '/') . '/';
        return $this->put($path, '', []);
    }

    public function deleteDirectory(string $path): bool
    {
        $path = rtrim($path, '/') . '/';
        
        $paginator = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $path,
        ]);

        foreach ($paginator as $page) {
            $objects = [];
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[] = ['Key' => $object['Key']];
            }

            if (!empty($objects)) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $objects],
                ]);
            }
        }

        return true;
    }

    public function getDriver(): string
    {
        return 's3';
    }

    private function getAcl(array $options = []): string
    {
        $visibility = $options['visibility'] ?? $this->visibility;
        return $visibility === 'public' ? 'public-read' : 'private';
    }

    private function extractOptions(array $options): array
    {
        $s3Options = [];
        if (isset($options['mime_type'])) {
            $s3Options['ContentType'] = $options['mime_type'];
        }
        if (isset($options['metadata'])) {
            $s3Options['Metadata'] = $options['metadata'];
        }
        return $s3Options;
    }

    private function isDirectory(string $key): bool
    {
        return str_ends_with($key, '/');
    }
}
