<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Driver;

use MonkeysLegion\Files\Contracts\CloudStorageInterface;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Google Cloud Storage driver with lazy client init.
 * Requires `google/cloud-storage ^1.35` (suggest dependency).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class GcsDriver implements CloudStorageInterface
{
    private ?\Google\Cloud\Storage\StorageClient $client = null;
    private ?\Google\Cloud\Storage\Bucket $bucketInstance = null;

    public function __construct(
        private readonly string $bucket,
        private readonly ?string $keyFilePath = null,
        private readonly ?string $projectId = null,
        private readonly string $prefix = '',
        private readonly Visibility $defaultVisibility = Visibility::Private,
    ) {}

    // ── Write ────────────────────────────────────────────────────

    public function put(string $path, string $contents, array $options = []): bool
    {
        $predefined = ($options['visibility'] ?? $this->defaultVisibility) === Visibility::Public
            ? 'publicRead' : 'private';

        $this->getBucket()->upload($contents, [
            'name'                => $this->prefixedPath($path),
            'predefinedAcl'       => $predefined,
            'metadata'            => ['contentType' => $options['content_type'] ?? 'application/octet-stream'],
        ]);

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream provided');
        }

        $predefined = ($options['visibility'] ?? $this->defaultVisibility) === Visibility::Public
            ? 'publicRead' : 'private';

        $this->getBucket()->upload($stream, [
            'name'          => $this->prefixedPath($path),
            'predefinedAcl' => $predefined,
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

    // ── Read ─────────────────────────────────────────────────────

    public function get(string $path): ?string
    {
        $object = $this->getBucket()->object($this->prefixedPath($path));

        try {
            return $object->downloadAsString();
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    public function getStream(string $path): mixed
    {
        $object = $this->getBucket()->object($this->prefixedPath($path));

        try {
            $stream = $object->downloadAsStream();

            return $stream->detach();
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    // ── Delete ───────────────────────────────────────────────────

    public function delete(string $path): bool
    {
        try {
            $this->getBucket()->object($this->prefixedPath($path))->delete();
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            // Already gone
        }

        return true;
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function exists(string $path): bool
    {
        return $this->getBucket()->object($this->prefixedPath($path))->exists();
    }

    public function size(string $path): ?int
    {
        try {
            $info = $this->getBucket()->object($this->prefixedPath($path))->info();

            return (int) ($info['size'] ?? 0);
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    public function mimeType(string $path): ?string
    {
        try {
            $info = $this->getBucket()->object($this->prefixedPath($path))->info();

            return $info['contentType'] ?? null;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $info = $this->getBucket()->object($this->prefixedPath($path))->info();
            $date = $info['updated'] ?? null;

            return $date !== null ? strtotime($date) : null;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    public function checksum(string $path, string $algo = 'sha256'): ?string
    {
        $contents = $this->get($path);

        return $contents !== null ? hash($algo, $contents) : null;
    }

    // ── Visibility ───────────────────────────────────────────────

    public function visibility(string $path): ?Visibility
    {
        try {
            $acl = $this->getBucket()->object($this->prefixedPath($path))->acl()->get();

            foreach ($acl as $entry) {
                if (($entry['entity'] ?? '') === 'allUsers') {
                    return Visibility::Public;
                }
            }

            return Visibility::Private;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $object = $this->getBucket()->object($this->prefixedPath($path));

        if ($visibility === Visibility::Public) {
            $object->acl()->add('allUsers', 'READER');
        } else {
            try {
                $object->acl()->delete('allUsers');
            } catch (\Exception) {
                // May not exist
            }
        }
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function copy(string $source, string $destination): bool
    {
        $this->getBucket()->object($this->prefixedPath($source))->copy(
            $this->bucket,
            ['name' => $this->prefixedPath($destination)],
        );

        return true;
    }

    public function move(string $source, string $destination): bool
    {
        if ($this->copy($source, $destination)) {
            return $this->delete($source);
        }

        return false;
    }

    // ── Directory ────────────────────────────────────────────────

    public function url(string $path): string
    {
        return "https://storage.googleapis.com/{$this->bucket}/{$this->prefixedPath($path)}";
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $prefix = $this->prefixedPath($directory);

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $options = ['prefix' => $prefix];

        if (!$recursive) {
            $options['delimiter'] = '/';
        }

        $files = [];

        foreach ($this->getBucket()->objects($options) as $object) {
            $name = $object->name();

            if ($name !== $prefix) {
                $files[] = $this->stripPrefix($name);
            }
        }

        return $files;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $prefix = $this->prefixedPath($directory);

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $objects = $this->getBucket()->objects([
            'prefix'    => $prefix,
            'delimiter' => '/',
        ]);

        $dirs = [];

        foreach ($objects->prefixes() as $p) {
            $dirs[] = rtrim($this->stripPrefix($p), '/');
        }

        return $dirs;
    }

    public function makeDirectory(string $path): bool
    {
        return $this->put(rtrim($path, '/') . '/', '');
    }

    public function deleteDirectory(string $path): bool
    {
        $prefix = $this->prefixedPath(rtrim($path, '/')) . '/';

        foreach ($this->getBucket()->objects(['prefix' => $prefix]) as $object) {
            $object->delete();
        }

        return true;
    }

    // ── Cloud-Specific ───────────────────────────────────────────

    public function temporaryUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $ttl = $expiration->getTimestamp() - time();

        return $this->getBucket()
            ->object($this->prefixedPath($path))
            ->signedUrl(new \DateTimeImmutable("+{$ttl} seconds"));
    }

    public function presignedUploadUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $ttl = $expiration->getTimestamp() - time();

        return $this->getBucket()
            ->object($this->prefixedPath($path))
            ->signedUrl(new \DateTimeImmutable("+{$ttl} seconds"), [
                'method'      => 'PUT',
                'contentType' => $options['content_type'] ?? 'application/octet-stream',
            ]);
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function getDriver(): string
    {
        return 'gcs';
    }

    // ── Internal ─────────────────────────────────────────────────

    private function getClient(): \Google\Cloud\Storage\StorageClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            throw new StorageException(
                'GCS driver requires google/cloud-storage ^1.35. Run: composer require google/cloud-storage',
            );
        }

        $config = [];

        if ($this->keyFilePath !== null) {
            $config['keyFilePath'] = $this->keyFilePath;
        }

        if ($this->projectId !== null) {
            $config['projectId'] = $this->projectId;
        }

        $this->client = new \Google\Cloud\Storage\StorageClient($config);

        return $this->client;
    }

    private function getBucket(): \Google\Cloud\Storage\Bucket
    {
        return $this->bucketInstance ??= $this->getClient()->bucket($this->bucket);
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
