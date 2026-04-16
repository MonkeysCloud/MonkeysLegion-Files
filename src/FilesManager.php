<?php
declare(strict_types=1);

namespace MonkeysLegion\Files;

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
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FilesManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];

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

    /**
     * @param array<string, StorageInterface> $disks        Named storage drivers
     * @param string                          $defaultDisk  Default disk name
     * @param UploadValidator|null            $validator    Upload validator
     * @param ContentValidator|null           $contentValidator MIME sniffing validator
     * @param LoggerInterface                 $logger       PSR logger
     */
    public function __construct(
        array $disks,
        private readonly string $defaultDisk = 'local',
        private readonly ?UploadValidator $validator = null,
        private readonly ?ContentValidator $contentValidator = null,
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

    /** Store a file from contents. */
    public function put(string $path, string $contents, ?string $disk = null, array $options = []): bool
    {
        return $this->disk($disk)->put($path, $contents, $options);
    }

    /** Store from a stream. */
    public function putStream(string $path, mixed $stream, ?string $disk = null, array $options = []): bool
    {
        return $this->disk($disk)->putStream($path, $stream, $options);
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

    /** Delete a file. */
    public function delete(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->delete($path);
    }

    /** Check if file exists. */
    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($path);
    }

    /** Get file size. */
    public function size(string $path, ?string $disk = null): ?int
    {
        return $this->disk($disk)->size($path);
    }

    /** Get MIME type. */
    public function mimeType(string $path, ?string $disk = null): ?string
    {
        return $this->disk($disk)->mimeType($path);
    }

    /** Get file checksum. */
    public function checksum(string $path, string $algo = 'sha256', ?string $disk = null): ?string
    {
        return $this->disk($disk)->checksum($path, $algo);
    }

    /** Get public URL. */
    public function url(string $path, ?string $disk = null): string
    {
        return $this->disk($disk)->url($path);
    }

    // ── Copy / Move (same disk) ──────────────────────────────────

    /** Copy a file within the same disk. */
    public function copy(string $source, string $destination, ?string $disk = null): bool
    {
        return $this->disk($disk)->copy($source, $destination);
    }

    /** Move a file within the same disk. */
    public function move(string $source, string $destination, ?string $disk = null): bool
    {
        return $this->disk($disk)->move($source, $destination);
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
            return $destDriver->putStream($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
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
            return $this->disk($sourceDisk)->delete($source);
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
        $safeOriginal = $this->sanitizeClientFilename($originalName);

        if ($options['preserve_name'] ?? false) {
            return $safeOriginal;
        }

        $ext = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
        $id  = bin2hex(random_bytes(16));

        return $ext !== '' ? "{$id}.{$ext}" : $id;
    }

    private function sanitizeClientFilename(string $name): string
    {
        $name = str_replace("\0", '', $name);
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x01-\x1F\x7F]+/', '', $name) ?? $name;
        $name = trim($name);

        return ($name !== '' && $name !== '.' && $name !== '..') ? $name : 'file';
    }
}
