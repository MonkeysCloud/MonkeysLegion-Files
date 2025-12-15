<?php

declare(strict_types=1);

namespace MonkeysLegion\Files;

use MonkeysLegion\Files\Cdn\CdnUrlGenerator;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Exception\ConfigurationException;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Image\ImageProcessor;
use MonkeysLegion\Files\Job\FileJobInterface;
use MonkeysLegion\Files\RateLimit\UploadRateLimiter;
use MonkeysLegion\Files\Repository\FileRepository;
use MonkeysLegion\Files\Security\VirusScannerInterface;
use MonkeysLegion\Files\Storage\LocalStorage;
use MonkeysLegion\Files\Storage\S3Storage;
use MonkeysLegion\Files\Upload\ChunkedUploadManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Main file management service.
 * 
 * Provides a unified interface for all file operations including storage,
 * uploads, image processing, and more.
 */
class FilesManager
{
    /** @var array<string, StorageInterface> */
    private array $disks = [];
    private string $defaultDisk = 'local';
    private ?FileRepository $repository;
    private ?ImageProcessor $imageProcessor = null;
    private ?ChunkedUploadManager $chunkedUploadManager = null;
    private ?UploadRateLimiter $rateLimiter;
    private ?VirusScannerInterface $virusScanner;
    private ?CdnUrlGenerator $cdn;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = $this->mergeConfig($config);
        $this->logger = $logger ?? new NullLogger();
        $this->defaultDisk = $this->config['default'] ?? $this->config['default_disk'] ?? 'local';
        
        $this->initializeDisks();
        $this->initializeOptionalServices();
    }

    /**
     * Get a storage disk.
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name = $name ?? $this->defaultDisk;

        if (!isset($this->disks[$name])) {
            throw new ConfigurationException("Disk '{$name}' is not configured");
        }

        return $this->disks[$name];
    }

    /**
     * Store a file from contents.
     */
    public function put(
        string $path,
        string $contents,
        ?string $disk = null,
        array $options = []
    ): FileRecord|bool {
        $storage = $this->disk($disk);
        $success = $storage->put($path, $contents, $options);

        if (!$success) {
            return false;
        }

        if ($this->repository && ($this->config['database']['enabled'] ?? false)) {
            return $this->createFileRecord($storage, $path, $contents, $options);
        }

        return true;
    }

    /**
     * Store a file from a stream.
     */
    public function putStream(
        string $path,
        mixed $stream,
        ?string $disk = null,
        array $options = []
    ): FileRecord|bool {
        $storage = $this->disk($disk);
        $success = $storage->putStream($path, $stream, $options);

        if (!$success) {
            return false;
        }

        if ($this->repository && ($this->config['database']['enabled'] ?? false)) {
            // Rewind stream to get info
            if (is_resource($stream)) {
                rewind($stream);
                $contents = stream_get_contents($stream);
            } else {
                $contents = '';
            }
            return $this->createFileRecord($storage, $path, $contents, $options);
        }

        return true;
    }

    /**
     * Store an uploaded file.
     */
    public function putFile(
        string $directory,
        array $uploadedFile,
        ?string $disk = null,
        array $options = []
    ): FileRecord|bool {
        // Rate limiting check
        if ($this->rateLimiter) {
            $identifier = $options['user_id'] ?? $options['ip'] ?? 'default';
            
            if (!$this->rateLimiter->canUpload($identifier, $uploadedFile['size'])) {
                throw new StorageException('Upload rate limit exceeded');
            }
            
            $this->rateLimiter->startUpload($identifier);
        }

        try {
            // Virus scan if configured
            if ($this->virusScanner && ($this->config['security']['virus_scan']['enabled'] ?? false)) {
                $result = $this->virusScanner->scan($uploadedFile['tmp_name']);
                
                if ($result->hasThreat()) {
                    $this->logger->warning('Virus detected in upload', [
                        'threat' => $result->threat,
                        'file' => $uploadedFile['name'],
                    ]);
                    throw new StorageException('File rejected: security threat detected');
                }
            }

            // Generate unique filename
            $filename = $this->generateFilename($uploadedFile['name'], $options);
            $path = rtrim($directory, '/') . '/' . $filename;

            // Store the file
            $stream = fopen($uploadedFile['tmp_name'], 'rb');
            
            try {
                $result = $this->putStream($path, $stream, $disk, array_merge($options, [
                    'original_name' => $uploadedFile['name'],
                    'mime_type' => $uploadedFile['type'],
                    'size' => $uploadedFile['size'],
                ]));
            } finally {
                fclose($stream);
            }

            // Process image if applicable
            if ($result instanceof FileRecord && $this->imageProcessor) {
                $this->processImageConversions($result, $disk);
            }

            // Record successful upload
            if ($this->rateLimiter) {
                $identifier = $options['user_id'] ?? $options['ip'] ?? 'default';
                $this->rateLimiter->recordUpload($identifier, $uploadedFile['size']);
            }

            return $result;
        } finally {
            if ($this->rateLimiter) {
                $identifier = $options['user_id'] ?? $options['ip'] ?? 'default';
                $this->rateLimiter->endUpload($identifier);
            }
        }
    }

    /**
     * Get file contents.
     */
    public function get(string $path, ?string $disk = null): ?string
    {
        $storage = $this->disk($disk);
        $contents = $storage->get($path);

        // Track access if database is enabled
        if ($contents !== null && $this->repository) {
            $record = $this->repository->findByPath($storage->getDriver(), $path);
            if ($record && $record->getId()) {
                $this->repository->recordAccess((string) $record->getId());
            }
        }

        return $contents;
    }

    /**
     * Get a read stream for the file.
     */
    public function getStream(string $path, ?string $disk = null): mixed
    {
        return $this->disk($disk)->getStream($path);
    }

    /**
     * Delete a file.
     */
    public function delete(string $path, ?string $disk = null): bool
    {
        $storage = $this->disk($disk);

        // Delete from database first
        if ($this->repository) {
            $record = $this->repository->findByPath($storage->getDriver(), $path);
            if ($record && $record->getId()) {
                $this->repository->softDelete((string) $record->getId());
            }
        }

        return $storage->delete($path);
    }

    /**
     * Delete multiple files.
     */
    public function deleteMultiple(array $paths, ?string $disk = null): bool
    {
        $storage = $this->disk($disk);
        $success = true;

        foreach ($paths as $path) {
            if (!$this->delete($path, $disk)) {
                $success = false;
            }
        }

        return $success;
    }

    // ... exists, size, mimeType, copy, move, url, temporaryUrl, files, directories ...
    // Skipping unrelated methods for brevity in replacement if possible, 
    // but replace_file_content needs contiguous block?
    // I can't skip methods in the middle.
    // I will replace delete method separately and setCache separately.
    // Wait, the block below "delete" is large.
    // I'll make 2 calls.


    /**
     * Check if file exists.
     */
    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($path);
    }

    /**
     * Get file size.
     */
    public function size(string $path, ?string $disk = null): ?int
    {
        return $this->disk($disk)->size($path);
    }

    /**
     * Get file MIME type.
     */
    public function mimeType(string $path, ?string $disk = null): ?string
    {
        return $this->disk($disk)->mimeType($path);
    }

    /**
     * Copy a file.
     */
    public function copy(
        string $source,
        string $destination,
        ?string $sourceDisk = null,
        ?string $destDisk = null
    ): bool {
        $sourceStorage = $this->disk($sourceDisk);
        $destStorage = $this->disk($destDisk);

        if ($sourceStorage === $destStorage) {
            return $sourceStorage->copy($source, $destination);
        }

        // Cross-disk copy
        $stream = $sourceStorage->getStream($source);
        
        if ($stream === null) {
            throw new FileNotFoundException($source);
        }

        try {
            return $destStorage->putStream($destination, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Move a file.
     */
    public function move(
        string $source,
        string $destination,
        ?string $sourceDisk = null,
        ?string $destDisk = null
    ): bool {
        $sourceStorage = $this->disk($sourceDisk);
        $destStorage = $this->disk($destDisk);

        if ($sourceStorage === $destStorage) {
            return $sourceStorage->move($source, $destination);
        }

        // Cross-disk move
        if ($this->copy($source, $destination, $sourceDisk, $destDisk)) {
            return $sourceStorage->delete($source);
        }

        return false;
    }

    /**
     * Get file URL.
     */
    public function url(string $path, ?string $disk = null): string
    {
        // Use CDN if configured
        if ($this->cdn && ($this->config['cdn']['enabled'] ?? false)) {
            return $this->cdn->url($path);
        }

        return $this->disk($disk)->url($path);
    }

    /**
     * Get temporary/signed URL.
     */
    public function temporaryUrl(
        string $path,
        \DateTimeInterface $expiration,
        ?string $disk = null,
        array $options = []
    ): string {
        // Use CDN if configured
        if ($this->cdn && ($this->config['cdn']['enabled'] ?? false)) {
            return $this->cdn->signedUrl($path, $expiration, $options);
        }

        return $this->disk($disk)->temporaryUrl($path, $expiration, $options);
    }

    /**
     * List files in a directory.
     */
    public function files(string $directory = '', bool $recursive = false, ?string $disk = null): array
    {
        return $this->disk($disk)->files($directory, $recursive);
    }

    /**
     * List directories.
     */
    public function directories(string $directory = '', bool $recursive = false, ?string $disk = null): array
    {
        return $this->disk($disk)->directories($directory, $recursive);
    }

    /**
     * Get the chunked upload manager.
     */
    public function chunked(): ChunkedUploadManager
    {
        if (!$this->chunkedUploadManager) {
            throw new ConfigurationException('Chunked upload manager is not configured');
        }

        return $this->chunkedUploadManager;
    }

    /**
     * Get the image processor.
     */
    public function images(): ImageProcessor
    {
        if (!$this->imageProcessor) {
            throw new ConfigurationException('Image processor is not configured');
        }

        return $this->imageProcessor;
    }

    /**
     * Get the file repository.
     */
    public function repository(): FileRepository
    {
        if (!$this->repository) {
            throw new ConfigurationException('File repository is not configured');
        }

        return $this->repository;
    }

    /**
     * Find file by UUID.
     */
    public function findByUuid(string $uuid): ?FileRecord
    {
        if (!$this->repository) {
            return null;
        }

        return $this->repository->findByUuid($uuid);
    }

    /**
     * Get files attached to a model.
     */
    public function getFilesFor(string $modelType, int $modelId, ?string $collection = null): array
    {
        if (!$this->repository) {
            return [];
        }

        return $this->repository->findByFileable($modelType, $modelId, $collection);
    }

    /**
     * Attach a file to a model.
     */
    public function attachTo(FileRecord $file, string $modelType, int $modelId, ?string $collection = null): FileRecord
    {
        $file->setFileable($modelType, $modelId);
        
        if ($collection !== null) {
            $file->setCollection($collection);
        }

        if ($this->repository) {
            $this->repository->save($file);
        }

        return $file;
    }

    /**
     * Dispatch a job for async processing.
     */
    public function dispatch(FileJobInterface $job): void
    {
        // This would integrate with the queue system
        // For now, just log the job
        $this->logger->info('Job dispatched', [
            'job' => $job->getName(),
            'data' => $job->toArray(),
        ]);
    }

    /**
     * Set the cache instance.
     */
    public function setCache(CacheInterface $cache): self
    {
        // Update chunked upload manager if exists
        if ($this->chunkedUploadManager) {
            $this->chunkedUploadManager = new ChunkedUploadManager(
                $this->disk(),
                $this->config['upload']['temp_dir'] ?? sys_get_temp_dir() . '/ml-uploads',
                $cache,
                $this->config['upload']['chunk_size'] ?? 5 * 1024 * 1024
            );
        }

        return $this;
    }

    /**
     * Set the file repository.
     */
    public function setRepository(FileRepository $repository): self
    {
        $this->repository = $repository;
        return $this;
    }

    /**
     * Set the virus scanner.
     */
    public function setVirusScanner(VirusScannerInterface $scanner): self
    {
        $this->virusScanner = $scanner;
        return $this;
    }

    /**
     * Set the CDN generator.
     */
    public function setCdn(CdnUrlGenerator $cdn): self
    {
        $this->cdn = $cdn;
        return $this;
    }

    /**
     * Set the rate limiter.
     */
    public function setRateLimiter(UploadRateLimiter $rateLimiter): self
    {
        $this->rateLimiter = $rateLimiter;
        return $this;
    }

    /**
     * Add a disk.
     */
    public function addDisk(string $name, StorageInterface $storage): self
    {
        $this->disks[$name] = $storage;
        return $this;
    }

    /**
     * Get configuration value.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Initialize storage disks.
     */
    private function initializeDisks(): void
    {
        foreach ($this->config['disks'] as $name => $diskConfig) {
            $this->disks[$name] = $this->createDisk($diskConfig);
        }
    }

    /**
     * Create a storage disk from configuration.
     */
    private function createDisk(array $config): StorageInterface
    {
        return match ($config['driver']) {
            'local' => new LocalStorage(
                $config['path'],
                $config['url'] ?? '',
                $config['permissions']['dir'] ?? 0755,
                $config['permissions']['file'] ?? 0644,
                $config['visibility'] ?? 'public'
            ),
            's3' => new S3Storage(
                bucket: $config['bucket'],
                region: $config['region'] ?? 'us-east-1',
                endpoint: $config['endpoint'] ?? null,
                accessKey: $config['key'] ?? null,
                secretKey: $config['secret'] ?? null,
                options: $config,
            ),
            default => throw new ConfigurationException("Unknown disk driver: {$config['driver']}"),
        };
    }

    /**
     * Initialize optional services.
     */
    private function initializeOptionalServices(): void
    {
        // Image processor
        if ($this->config['image']['enabled'] ?? true) {
            $this->imageProcessor = new ImageProcessor(
                $this->config['image']['driver'] ?? 'gd'
            );
        }

        // CDN
        if ($this->config['cdn']['enabled'] ?? false) {
            $this->cdn = new CdnUrlGenerator(
                $this->config['cdn']['url'],
                $this->config['cdn']['signing_key'] ?? null,
                $this->config['cdn']['default_ttl'] ?? 3600,
                $this->config['cdn']['provider'] ?? 'generic'
            );
        }
    }

    /**
     * Merge configuration with defaults.
     */
    private function mergeConfig(array $config): array
    {
        $defaults = [
            'default' => 'local',
            'default_disk' => 'local', // Legacy fallback
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'path' => '/var/www/storage/files',
                    'url' => '/files',
                    'visibility' => 'public',
                ],
            ],
            'upload' => [
                'max_size' => 100 * 1024 * 1024,
                'chunk_size' => 5 * 1024 * 1024,
            ],
            'image' => [
                'enabled' => true,
                'driver' => 'gd',
                'conversions' => [],
            ],
            'database' => [
                'enabled' => false,
            ],
            'cdn' => [
                'enabled' => false,
            ],
            'security' => [
                'virus_scan' => [
                    'enabled' => false,
                ],
            ],
        ];

        return array_replace_recursive($defaults, $config);
    }

    /**
     * Generate a unique filename.
     */
    private function generateFilename(string $originalName, array $options): string
    {
        $preserveName = $options['preserve_name'] ?? false;
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        if ($preserveName) {
            return $originalName;
        }

        $uniqueId = bin2hex(random_bytes(16));
        
        return $extension ? "{$uniqueId}.{$extension}" : $uniqueId;
    }

    /**
     * Create a file record in the database.
     */
    private function createFileRecord(
        StorageInterface $storage,
        string $path,
        string $contents,
        array $options
    ): FileRecord {
        $record = new FileRecord(
            $storage->getDriver(),
            $path,
            $options['original_name'] ?? basename($path),
            $options['mime_type'] ?? $storage->mimeType($path) ?? 'application/octet-stream',
            $options['size'] ?? strlen($contents)
        );

        if ($this->config['upload']['generate_checksum'] ?? false) {
            $record->setChecksum(hash('sha256', $contents), 'sha256');
        }

        if (isset($options['collection'])) {
            $record->setCollection($options['collection']);
        }

        if (isset($options['metadata'])) {
            $record->setMetadata($options['metadata']);
        }

        $this->repository->save($record);

        return $record;
    }

    /**
     * Process image conversions.
     */
    private function processImageConversions(FileRecord $record, ?string $disk): void
    {
        if (!$record->isImage()) {
            return;
        }

        $conversions = $this->config['image']['conversions'] ?? [];
        
        if (empty($conversions)) {
            return;
        }

        // Check if async processing is enabled
        if ($this->config['image']['async_processing'] ?? false) {
            // Dispatch job for async processing
            $job = new Job\ProcessImageJob(
                $record->getPath(),
                $disk ?? $this->defaultDisk,
                $conversions
            );
            $this->dispatch($job);
        } else {
            // Process synchronously
            $storage = $this->disk($disk);
            $sourcePath = $storage instanceof LocalStorage 
                ? $storage->getFullPath($record->getPath())
                : null;

            if ($sourcePath && file_exists($sourcePath)) {
                foreach ($conversions as $name => $config) {
                    $outputPath = $this->getConversionPath($record->getPath(), $name);
                    $fullOutputPath = $storage instanceof LocalStorage
                        ? $storage->getFullPath($outputPath)
                        : sys_get_temp_dir() . '/' . basename($outputPath);

                    $this->imageProcessor->thumbnail(
                        $storage,
                        $sourcePath,
                        $config['width'] ?? 200,
                        $config['height'] ?? 200,
                        $config['fit'] ?? 'cover'
                    );
                }
            }
        }
    }

    /**
     * Get the path for a conversion.
     */
    private function getConversionPath(string $originalPath, string $conversionName): string
    {
        $info = pathinfo($originalPath);
        return sprintf(
            '%s/%s_%s.%s',
            $info['dirname'],
            $info['filename'],
            $conversionName,
            $info['extension'] ?? 'jpg'
        );
    }
}
