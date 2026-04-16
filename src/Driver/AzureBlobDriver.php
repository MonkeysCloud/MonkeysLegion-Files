<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Driver;

use MonkeysLegion\Files\Contracts\CloudStorageInterface;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Azure Blob Storage driver with lazy client initialization,
 * signed URLs (SAS tokens), and presigned upload support.
 *
 * Requires `microsoft/azure-storage-blob ^1.5` (suggest dependency).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class AzureBlobDriver implements CloudStorageInterface
{
    private ?object $client = null;

    public function __construct(
        private readonly string $connectionString,
        private readonly string $container,
        private readonly string $prefix = '',
        private readonly Visibility $defaultVisibility = Visibility::Private,
        /** @var array<string, mixed> */
        private readonly array $options = [],
    ) {}

    // ── Write Operations ─────────────────────────────────────────

    public function put(string $path, string $contents, array $options = []): bool
    {
        $key  = $this->prefixedPath($path);
        $blob = $this->getClient();

        $blobOptions = new \MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions();
        $contentType = $options['content_type'] ?? $this->detectMimeFromContents($contents);
        $blobOptions->setContentType($contentType);

        $blob->createBlockBlob($this->container, $key, $contents, $blobOptions);

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream provided');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new StorageException('Failed to read stream');
        }

        return $this->put($path, $contents, $options);
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
            $result = $this->getClient()->getBlob($this->container, $this->prefixedPath($path));
            $stream = $result->getContentStream();

            return stream_get_contents($stream) ?: null;
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new StorageException("Azure get failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getStream(string $path): mixed
    {
        try {
            $result = $this->getClient()->getBlob($this->container, $this->prefixedPath($path));

            return $result->getContentStream();
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new StorageException("Azure getStream failed: {$e->getMessage()}", 0, $e);
        }
    }

    // ── Delete Operations ────────────────────────────────────────

    public function delete(string $path): bool
    {
        try {
            $this->getClient()->deleteBlob($this->container, $this->prefixedPath($path));
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw new StorageException("Azure delete failed: {$e->getMessage()}", 0, $e);
            }
        }

        return true;
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function exists(string $path): bool
    {
        try {
            $this->getClient()->getBlobProperties($this->container, $this->prefixedPath($path));

            return true;
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException) {
            return false;
        }
    }

    public function size(string $path): ?int
    {
        try {
            $props = $this->getClient()->getBlobProperties(
                $this->container,
                $this->prefixedPath($path),
            );

            return (int) $props->getProperties()->getContentLength();
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException) {
            return null;
        }
    }

    public function mimeType(string $path): ?string
    {
        try {
            $props = $this->getClient()->getBlobProperties(
                $this->container,
                $this->prefixedPath($path),
            );

            return $props->getProperties()->getContentType();
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $props = $this->getClient()->getBlobProperties(
                $this->container,
                $this->prefixedPath($path),
            );

            $date = $props->getProperties()->getLastModified();

            return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException) {
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
            $acl = $this->getClient()->getContainerAcl($this->container);
            $access = $acl->getContainerAccess();

            return $access === 'container' || $access === 'blob'
                ? Visibility::Public
                : Visibility::Private;
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException) {
            return null;
        }
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        // Azure visibility is container-level, not per-blob
        // This is a no-op for individual files — use SAS tokens for access control
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function copy(string $source, string $destination): bool
    {
        $srcKey  = $this->prefixedPath($source);
        $destKey = $this->prefixedPath($destination);

        $sourceUrl = sprintf(
            '%s/%s/%s',
            rtrim($this->getClient()->getUri(), '/'),
            $this->container,
            $srcKey,
        );

        $this->getClient()->copyBlob($this->container, $destKey, $sourceUrl);

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
        $endpoint = $this->options['url'] ?? $this->getClient()->getUri();

        return rtrim($endpoint, '/') . '/' . $this->container . '/' . $this->prefixedPath($path);
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $prefix = $this->prefixedPath($directory);

        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $options = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $options->setPrefix($prefix);

        if (!$recursive) {
            $options->setDelimiter('/');
        }

        $result = $this->getClient()->listBlobs($this->container, $options);
        $files  = [];

        foreach ($result->getBlobs() as $blob) {
            $name = $blob->getName();

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

        $options = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $options->setPrefix($prefix);
        $options->setDelimiter('/');

        $result = $this->getClient()->listBlobs($this->container, $options);
        $dirs   = [];

        foreach ($result->getBlobPrefixes() as $blobPrefix) {
            $dirs[] = rtrim($this->stripPrefix($blobPrefix->getName()), '/');
        }

        return $dirs;
    }

    public function makeDirectory(string $path): bool
    {
        // Azure Blob has no real directories — store a zero-byte marker
        return $this->put(rtrim($path, '/') . '/', '');
    }

    public function deleteDirectory(string $path): bool
    {
        $prefix = $this->prefixedPath(rtrim($path, '/')) . '/';

        $options = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $options->setPrefix($prefix);

        $result = $this->getClient()->listBlobs($this->container, $options);

        foreach ($result->getBlobs() as $blob) {
            $this->getClient()->deleteBlob($this->container, $blob->getName());
        }

        return true;
    }

    // ── Cloud-Specific (SAS) ─────────────────────────────────────

    public function temporaryUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $key  = $this->prefixedPath($path);
        $sas  = $this->generateSas($key, 'r', $expiration);
        $base = $this->url($path);

        return $base . '?' . $sas;
    }

    public function presignedUploadUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $key  = $this->prefixedPath($path);
        $sas  = $this->generateSas($key, 'cw', $expiration);
        $base = $this->url($path);

        return $base . '?' . $sas;
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function getDriver(): string
    {
        return 'azure';
    }

    // ── Internal ─────────────────────────────────────────────────

    /**
     * @return \MicrosoftAzure\Storage\Blob\BlobRestProxy
     */
    private function getClient(): object
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\MicrosoftAzure\Storage\Blob\BlobRestProxy::class)) {
            throw new StorageException(
                'Azure driver requires microsoft/azure-storage-blob ^1.5. '
                . 'Run: composer require microsoft/azure-storage-blob',
            );
        }

        $this->client = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService(
            $this->connectionString,
        );

        return $this->client;
    }

    /**
     * Generate a SAS (Shared Access Signature) token string.
     *
     * @param string             $blobKey     Blob key
     * @param string             $permissions SAS permissions: r=read, c=create, w=write, d=delete
     * @param \DateTimeInterface $expiration  Expiration time
     */
    private function generateSas(
        string $blobKey,
        string $permissions,
        \DateTimeInterface $expiration,
    ): string {
        $helper = new \MicrosoftAzure\Storage\Common\SharedAccessSignatureHelper(
            $this->extractAccountName(),
            $this->extractAccountKey(),
        );

        return $helper->generateBlobServiceSharedAccessSignatureToken(
            'b',
            $this->container . '/' . $blobKey,
            $permissions,
            $expiration,
            new \DateTimeImmutable(),
        );
    }

    private function extractAccountName(): string
    {
        if (preg_match('/AccountName=([^;]+)/i', $this->connectionString, $m)) {
            return $m[1];
        }

        throw new StorageException('Cannot extract AccountName from connection string');
    }

    private function extractAccountKey(): string
    {
        if (preg_match('/AccountKey=([^;]+)/i', $this->connectionString, $m)) {
            return $m[1];
        }

        throw new StorageException('Cannot extract AccountKey from connection string');
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

    private function detectMimeFromContents(string $contents): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($contents);

        return $mime !== false ? $mime : 'application/octet-stream';
    }
}
