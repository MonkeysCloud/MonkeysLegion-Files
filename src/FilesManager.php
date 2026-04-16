<?php
declare(strict_types=1);

namespace MonkeysLegion\Files;

use MonkeysLegion\Cache\CacheInterface;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Upload\UploadResult;
use MonkeysLegion\Files\Upload\UploadValidator;
use MonkeysLegion\Files\Security\ContentValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Unified file management facade. Provides a single entry point for
 * storage, uploads, and cross-disk operations.
 *
 * Uses constructor DI — no setter methods.
 * Integrates MonkeysLegion Cache 2.0 for metadata caching.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FilesManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];

    /** Default metadata cache TTL: 5 minutes. */
    private const int CACHE_TTL = 300;

    /** Cache key prefix for files metadata. */
    private const string CACHE_PREFIX = 'ml_files:';

    /**
     * Number of registered disks (computed via get hook).
     */
    public int $diskCount {
        get => count($this->disks);
    }

    /** Names of all registered disks. */
    public array $diskNames {
        get => array_keys($this->disks);
    }

    /** The default disk name. */
    public string $defaultDiskName {
        get => $this->defaultDisk;
    }

    /** Whether upload validation is configured. */
    public bool $hasValidator {
        get => $this->validator !== null;
    }

    /** Whether content sniffing is configured. */
    public bool $hasContentValidator {
        get => $this->contentValidator !== null;
    }

    /** Whether caching is enabled. */
    public bool $isCacheEnabled {
        get => $this->cache !== null;
    }

    /**
     * @param array<string, StorageInterface> $disks             Named storage drivers
     * @param string                          $defaultDisk       Default disk name
     * @param UploadValidator|null            $validator         Upload validator
     * @param ContentValidator|null           $contentValidator  MIME sniffing validator
     * @param CacheInterface|null             $cache             MonkeysLegion Cache 2.0
     * @param int                             $cacheTtl          Metadata cache TTL in seconds
     * @param LoggerInterface                 $logger            PSR logger
     */
    public function __construct(
        array $disks,
        private readonly string $defaultDisk = 'local',
        private readonly ?UploadValidator $validator = null,
        private readonly ?ContentValidator $contentValidator = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = self::CACHE_TTL,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        foreach ($disks as $name => $driver) {
            $this->disks[$name] = $driver;
        }
    }

    // ── Disk Access ──────────────────────────────────────────────

    /**
     * Get a storage disk by name (or the default).
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name ??= $this->defaultDisk;

        return $this->disks[$name]
            ?? throw new StorageException("Disk '{$name}' is not configured");
    }

    /**
     * Register an additional disk at runtime.
     */
    public function addDisk(string $name, StorageInterface $driver): void
    {
        $this->disks[$name] = $driver;
    }

    // ── File Operations ──────────────────────────────────────────

    /** Store a file from contents. Invalidates metadata cache. */
    public function put(string $path, string $contents, ?string $disk = null, array $options = []): bool
    {
        $result = $this->disk($disk)->put($path, $contents, $options);

        if ($result) {
            $this->invalidateCache($path, $disk);
        }

        return $result;
    }

    /** Store from a stream. Invalidates metadata cache. */
    public function putStream(string $path, mixed $stream, ?string $disk = null, array $options = []): bool
    {
        $result = $this->disk($disk)->putStream($path, $stream, $options);

        if ($result) {
            $this->invalidateCache($path, $disk);
        }

        return $result;
    }

    /** Get file contents. */
    public function get(string $path, ?string $disk = null): ?string
    {
        return $this->disk($disk)->get($path);
    }

    /** Get a readable stream. */
    public function getStream(string $path, ?string $disk = null): mixed
    {
        return $this->disk($disk)->getStream($path);
    }

    /** Delete a file. Invalidates metadata cache. */
    public function delete(string $path, ?string $disk = null): bool
    {
        $result = $this->disk($disk)->delete($path);

        if ($result) {
            $this->invalidateCache($path, $disk);
        }

        return $result;
    }

    /** Check if file exists. */
    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($path);
    }

    /**
     * Get file size with caching.
     *
     * Uses ML Cache 2.0 `remember()` to avoid repeated I/O.
     */
    public function size(string $path, ?string $disk = null): ?int
    {
        return $this->cachedMetadata($path, $disk, 'size', fn () => $this->disk($disk)->size($path));
    }

    /**
     * Get MIME type with caching.
     *
     * Uses ML Cache 2.0 `remember()` to avoid repeated I/O.
     */
    public function mimeType(string $path, ?string $disk = null): ?string
    {
        return $this->cachedMetadata($path, $disk, 'mime', fn () => $this->disk($disk)->mimeType($path));
    }

    /**
     * Get file checksum with caching.
     *
     * Uses ML Cache 2.0 `remember()` to avoid repeated I/O.
     */
    public function checksum(string $path, string $algo = 'sha256', ?string $disk = null): ?string
    {
        return $this->cachedMetadata(
            $path,
            $disk,
            "checksum:{$algo}",
            fn () => $this->disk($disk)->checksum($path, $algo),
        );
    }

    /** Get public URL. */
    public function url(string $path, ?string $disk = null): string
    {
        return $this->disk($disk)->url($path);
    }

    // ── Copy / Move (same disk) ──────────────────────────────────

    /** Copy a file within the same disk. Invalidates cache for destination. */
    public function copy(string $source, string $destination, ?string $disk = null): bool
    {
        $result = $this->disk($disk)->copy($source, $destination);

        if ($result) {
            $this->invalidateCache($destination, $disk);
        }

        return $result;
    }

    /** Move a file within the same disk. Invalidates cache for both paths. */
    public function move(string $source, string $destination, ?string $disk = null): bool
    {
        $result = $this->disk($disk)->move($source, $destination);

        if ($result) {
            $this->invalidateCache($source, $disk);
            $this->invalidateCache($destination, $disk);
        }

        return $result;
    }

    // ── Cross-Disk Operations ────────────────────────────────────

    /**
     * Copy a file between two different disks.
     * Reads from source disk, writes to destination disk.
     */
    public function crossDiskCopy(
        string $source,
        string $destination,
        string $sourceDisk,
        string $destDisk,
    ): bool {
        $srcDriver  = $this->disk($sourceDisk);
        $destDriver = $this->disk($destDisk);

        if ($srcDriver === $destDriver) {
            return $srcDriver->copy($source, $destination);
        }

        $stream = $srcDriver->getStream($source);

        if ($stream === null) {
            throw new FileNotFoundException($source);
        }

        try {
            $result = $destDriver->putStream($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($result) {
            $this->invalidateCache($destination, $destDisk);
        }

        return $result;
    }

    /**
     * Move a file between two different disks (atomic: copy then delete).
     */
    public function crossDiskMove(
        string $source,
        string $destination,
        string $sourceDisk,
        string $destDisk,
    ): bool {
        if ($this->crossDiskCopy($source, $destination, $sourceDisk, $destDisk)) {
            $result = $this->disk($sourceDisk)->delete($source);

            if ($result) {
                $this->invalidateCache($source, $sourceDisk);
            }

            return $result;
        }

        return false;
    }

    // ── Upload ───────────────────────────────────────────────────

    /**
     * Store an uploaded file with validation and MIME sniffing.
     *
     * @param UploadedFile         $file      Uploaded file VO
     * @param string               $directory Target directory
     * @param string|null          $disk      Target disk
     * @param array<string, mixed> $options   Extra options
     */
    public function upload(
        UploadedFile $file,
        string $directory,
        ?string $disk = null,
        array $options = [],
    ): UploadResult {
        // Validate if validator is configured
        if ($this->validator !== null) {
            try {
                $this->validator->validate($file);
            } catch (\MonkeysLegion\Files\Exception\ValidationException $e) {
                return UploadResult::fail($e->errors);
            }
        }

        // MIME content sniffing
        if ($this->contentValidator !== null) {
            try {
                $this->contentValidator->validate($file->tmpPath, $file->mimeType);
            } catch (\MonkeysLegion\Files\Exception\SecurityException $e) {
                $this->logger->warning('MIME spoofing attempt blocked', [
                    'file' => $file->clientName,
                    'error' => $e->getMessage(),
                ]);

                return UploadResult::fail([$e->getMessage()]);
            }
        }

        // Generate unique filename
        $filename = $this->generateFilename($file->clientName, $options);
        $path = rtrim($directory, '/') . '/' . $filename;

        // Store
        $stream = $file->getStream();

        try {
            $success = $this->disk($disk)->putStream($path, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$success) {
            return UploadResult::fail(['Failed to store file']);
        }

        $record = new FileRecord(
            disk: $disk ?? $this->defaultDisk,
            path: $path,
            originalName: $file->clientName,
            mimeType: $file->mimeType,
            size: $file->size,
        );

        $this->logger->info('File uploaded', [
            'path' => $path,
            'disk' => $disk ?? $this->defaultDisk,
            'size' => $file->size,
        ]);

        return UploadResult::ok($record);
    }

    // ── Cache Management ────────────────────────────────────────

    /**
     * Flush all cached file metadata.
     *
     * Useful after bulk operations or deployments.
     */
    public function flushMetadataCache(): bool
    {
        if ($this->cache === null) {
            return false;
        }

        return $this->cache->clear();
    }

    // ── Listing ──────────────────────────────────────────────────

    /**
     * List files in a directory.
     *
     * @return list<string>
     */
    public function files(string $directory = '', bool $recursive = false, ?string $disk = null): array
    {
        return $this->disk($disk)->files($directory, $recursive);
    }

    /**
     * List directories.
     *
     * @return list<string>
     */
    public function directories(string $directory = '', bool $recursive = false, ?string $disk = null): array
    {
        return $this->disk($disk)->directories($directory, $recursive);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function generateFilename(string $originalName, array $options): string
    {
        if ($options['preserve_name'] ?? false) {
            return $originalName;
        }

        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $id  = bin2hex(random_bytes(16));

        return $ext !== '' ? "{$id}.{$ext}" : $id;
    }

    /**
     * Build a cache key for file metadata.
     */
    private function cacheKey(string $path, ?string $disk, string $field): string
    {
        $diskName = $disk ?? $this->defaultDisk;
        return self::CACHE_PREFIX . "{$diskName}:{$path}:{$field}";
    }

    /**
     * Retrieve metadata via cache or compute it.
     *
     * Uses ML Cache 2.0 `remember()` when available.
     *
     * @template T
     * @param \Closure(): T $compute
     * @return T
     */
    private function cachedMetadata(string $path, ?string $disk, string $field, \Closure $compute): mixed
    {
        if ($this->cache === null) {
            return $compute();
        }

        $key = $this->cacheKey($path, $disk, $field);

        return $this->cache->remember($key, $this->cacheTtl, $compute);
    }

    /**
     * Invalidate all cached metadata for a file path.
     */
    private function invalidateCache(string $path, ?string $disk): void
    {
        if ($this->cache === null) {
            return;
        }

        $diskName = $disk ?? $this->defaultDisk;
        $prefix   = self::CACHE_PREFIX . "{$diskName}:{$path}:";

        // Delete known metadata keys
        $this->cache->deleteMultiple([
            $prefix . 'size',
            $prefix . 'mime',
            $prefix . 'checksum:sha256',
            $prefix . 'checksum:md5',
        ]);
    }
}
