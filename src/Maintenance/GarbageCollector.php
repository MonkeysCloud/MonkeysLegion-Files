<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Maintenance;

use DateTimeImmutable;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Repository\FileRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Garbage collector for file cleanup operations.
 * 
 * Handles cleanup of:
 * - Soft-deleted files
 * - Orphaned files (uploaded but never attached)
 * - Incomplete chunked uploads
 * - Unused image conversions
 */
class GarbageCollector
{
    private FileRepository $repository;
    private StorageInterface $storage;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        FileRepository $repository,
        StorageInterface $storage,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->repository = $repository;
        $this->storage = $storage;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge([
            'deleted_files_days' => 30,
            'orphaned_files_hours' => 24,
            'incomplete_uploads_hours' => 24,
            'unused_conversions_days' => 7,
            'chunk_temp_path' => '/tmp/chunks',
            'batch_size' => 100,
            'dry_run' => false,
        ], $config);
    }

    /**
     * Run all cleanup tasks.
     */
    public function runAll(): CleanupReport
    {
        $report = new CleanupReport();

        $report->addTask('deleted_files', $this->cleanupDeletedFiles());
        $report->addTask('orphaned_files', $this->cleanupOrphanedFiles());
        $report->addTask('incomplete_uploads', $this->cleanupIncompleteUploads());

        $this->logger->info('Garbage collection completed', [
            'total_files_removed' => $report->getTotalFilesRemoved(),
            'total_space_freed' => $report->getTotalSpaceFreed(),
            'duration' => $report->getDuration(),
        ]);

        return $report;
    }

    /**
     * Clean up soft-deleted files older than configured days.
     */
    public function cleanupDeletedFiles(): TaskResult
    {
        $result = new TaskResult('deleted_files');
        $days = $this->config['deleted_files_days'];
        
        $this->logger->info("Cleaning up files deleted more than {$days} days ago");

        try {
            $date = (new DateTimeImmutable())->modify("-{$days} days");
            $files = $this->repository->getDeletedBefore($date);
            
            foreach ($files as $file) {
                $result->incrementTotal();
                
                if ($this->config['dry_run']) {
                    $result->incrementSkipped('dry_run');
                    continue;
                }

                try {
                    // Delete from storage
                    if ($this->storage->exists($file->getPath())) {
                        $size = $this->storage->size($file->getPath()) ?? 0;
                        $this->storage->delete($file->getPath());
                        $result->addSpaceFreed($size);
                    }

                    // Permanently delete from database
                    $this->repository->forceDelete((string)$file->getId());
                    $result->incrementSuccess();

                    $this->logger->debug('Permanently deleted file', [
                        'id' => $file->getId(),
                        'path' => $file->getPath(),
                    ]);
                } catch (\Throwable $e) {
                    $result->addError($file->getId(), $e->getMessage());
                    $this->logger->error('Failed to delete file', [
                        'id' => $file->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $result->setFailed($e->getMessage());
            $this->logger->error('Deleted files cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $result->finish();
        return $result;
    }

    /**
     * Clean up orphaned files (uploaded but never attached to a model).
     */
    public function cleanupOrphanedFiles(): TaskResult
    {
        $result = new TaskResult('orphaned_files');
        $hours = $this->config['orphaned_files_hours'];
        
        $this->logger->info("Cleaning up orphaned files older than {$hours} hours");

        try {
            // This needs logic to filter by time, assuming getOrphanedFiles returns all
            // Or we rely on repo to filter. Current repo getOrphanedFiles doesn't filter by time.
            // We'll filter in loop.
            $files = $this->repository->getOrphanedFiles();
            $cutoff = (new DateTimeImmutable())->modify("-{$hours} hours");
            
            foreach ($files as $file) {
                if ($file->getCreatedAt() > $cutoff) {
                   continue; 
                }
                
                $result->incrementTotal();
                
                if ($this->config['dry_run']) {
                    $result->incrementSkipped('dry_run');
                    continue;
                }

                try {
                    // Delete from storage
                    if ($this->storage->exists($file->getPath())) {
                        $size = $this->storage->size($file->getPath()) ?? 0;
                        $this->storage->delete($file->getPath());
                        $result->addSpaceFreed($size);
                    }

                    // Delete from database
                    $this->repository->softDelete((string)$file->getId());
                    $result->incrementSuccess();

                    $this->logger->debug('Deleted orphaned file', [
                        'id' => $file->getId(),
                        'path' => $file->getPath(),
                    ]);
                } catch (\Throwable $e) {
                    $result->addError($file->getId(), $e->getMessage());
                    $this->logger->error('Failed to delete orphaned file', [
                        'id' => $file->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $result->setFailed($e->getMessage());
            $this->logger->error('Orphaned files cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $result->finish();
        return $result;
    }

    /**
     * Clean up incomplete chunked uploads.
     */
    public function cleanupIncompleteUploads(): TaskResult
    {
        $result = new TaskResult('incomplete_uploads');
        $hours = $this->config['incomplete_uploads_hours'];
        $chunkPath = $this->config['chunk_temp_path'];
        
        $this->logger->info("Cleaning up incomplete uploads older than {$hours} hours");

        try {
            if (!is_dir($chunkPath)) {
                $result->finish();
                return $result;
            }

            $cutoff = (new DateTimeImmutable())->modify("-{$hours} hours")->getTimestamp();
            
            $iterator = new \DirectoryIterator($chunkPath);
            
            foreach ($iterator as $item) {
                if ($item->isDot()) {
                    continue;
                }

                $result->incrementTotal();

                // Check if directory (upload session) or file (chunk)
                if ($item->isDir()) {
                    // Check session directory modification time
                    if ($item->getMTime() < $cutoff) {
                        if ($this->config['dry_run']) {
                            $result->incrementSkipped('dry_run');
                            continue;
                        }

                        $sessionPath = $item->getPathname();
                        $size = $this->getDirectorySize($sessionPath);
                        
                        if ($this->deleteDirectory($sessionPath)) {
                            $result->incrementSuccess();
                            $result->addSpaceFreed($size);
                            
                            $this->logger->debug('Deleted incomplete upload session', [
                                'path' => $sessionPath,
                            ]);
                        } else {
                            $result->addError($sessionPath, 'Failed to delete directory');
                        }
                    } else {
                        $result->incrementSkipped('not_expired');
                    }
                } elseif ($item->isFile()) {
                    // Individual chunk file
                    if ($item->getMTime() < $cutoff) {
                        if ($this->config['dry_run']) {
                            $result->incrementSkipped('dry_run');
                            continue;
                        }

                        $size = $item->getSize();
                        
                        if (unlink($item->getPathname())) {
                            $result->incrementSuccess();
                            $result->addSpaceFreed($size);
                        } else {
                            $result->addError($item->getPathname(), 'Failed to delete file');
                        }
                    } else {
                        $result->incrementSkipped('not_expired');
                    }
                }
            }
        } catch (\Throwable $e) {
            $result->setFailed($e->getMessage());
            $this->logger->error('Incomplete uploads cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $result->finish();
        return $result;
    }

    /**
     * Clean up unused image conversions.
     */
    public function cleanupUnusedConversions(string $conversionPattern = '_*'): TaskResult
    {
        $result = new TaskResult('unused_conversions');
        $days = $this->config['unused_conversions_days'];
        
        $this->logger->info("Cleaning up unused conversions older than {$days} days");

        try {
            // This would need to be implemented based on how conversions are stored
            // For now, we'll look for files matching the conversion pattern
            // that don't have a parent file in the database
            
            $allFiles = $this->storage->files('', true);
            $cutoff = (new DateTimeImmutable())->modify("-{$days} days")->getTimestamp();
            
            foreach ($allFiles as $path) {
                // Check if it looks like a conversion
                if (!$this->isConversionFile($path, $conversionPattern)) {
                    continue;
                }

                $result->incrementTotal();
                
                $lastModified = $this->storage->lastModified($path);
                
                if ($lastModified === null || $lastModified > $cutoff) {
                    $result->incrementSkipped('not_expired');
                    continue;
                }

                // Check if parent file exists
                $parentPath = $this->getParentPath($path, $conversionPattern);
                
                if ($parentPath && $this->storage->exists($parentPath)) {
                    $result->incrementSkipped('has_parent');
                    continue;
                }

                // Check if parent is tracked in database
                $parentRecord = $this->repository->findByPath(
                    $this->storage->getDriver(),
                    $parentPath ?? $path
                );

                if ($parentRecord !== null) {
                    $result->incrementSkipped('tracked');
                    continue;
                }

                if ($this->config['dry_run']) {
                    $result->incrementSkipped('dry_run');
                    continue;
                }

                try {
                    $size = $this->storage->size($path) ?? 0;
                    $this->storage->delete($path);
                    $result->incrementSuccess();
                    $result->addSpaceFreed($size);

                    $this->logger->debug('Deleted unused conversion', [
                        'path' => $path,
                    ]);
                } catch (\Throwable $e) {
                    $result->addError($path, $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $result->setFailed($e->getMessage());
            $this->logger->error('Unused conversions cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $result->finish();
        return $result;
    }

    /**
     * Empty the trash (permanently delete all soft-deleted files).
     */
    public function emptyTrash(): TaskResult
    {
        $result = new TaskResult('empty_trash');
        
        $this->logger->info('Emptying trash');

        try {
            $files = $this->repository->getDeletedBefore(new DateTimeImmutable());
            
            foreach ($files as $file) {
                $result->incrementTotal();
                
                if ($this->config['dry_run']) {
                    $result->incrementSkipped('dry_run');
                    continue;
                }

                try {
                    if ($this->storage->exists($file->getPath())) {
                        $size = $this->storage->size($file->getPath()) ?? 0;
                        $this->storage->delete($file->getPath());
                        $result->addSpaceFreed($size);
                    }

                    $this->repository->forceDelete((string)$file->getId());
                    $result->incrementSuccess();
                } catch (\Throwable $e) {
                    $result->addError($file->getId(), $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $result->setFailed($e->getMessage());
        }

        $result->finish();
        return $result;
    }

    /**
     * Synchronize database with storage (find files in storage not in DB).
     */
    public function findUntracked(): array
    {
        $untracked = [];
        $allFiles = $this->storage->files('', true);
        
        foreach ($allFiles as $path) {
            $record = $this->repository->findByPath(
                $this->storage->getDriver(),
                $path
            );

            if ($record === null) {
                $untracked[] = [
                    'path' => $path,
                    'size' => $this->storage->size($path),
                    'mime_type' => $this->storage->mimeType($path),
                    'last_modified' => $this->storage->lastModified($path),
                ];
            }
        }

        return $untracked;
    }

    private function isConversionFile(string $path, string $pattern): bool
    {
        // Simple pattern matching for conversion files
        // e.g., image_thumb.jpg, image_large.png
        $pattern = str_replace('*', '.+', preg_quote($pattern, '/'));
        $basename = pathinfo($path, PATHINFO_FILENAME);
        
        return (bool) preg_match("/{$pattern}$/", $basename);
    }

    private function getParentPath(string $path, string $pattern): ?string
    {
        $dir = dirname($path);
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        // Remove conversion suffix to get parent filename
        $pattern = str_replace('*', '.+', preg_quote($pattern, '/'));
        $parentBasename = preg_replace("/{$pattern}$/", '', $basename);
        
        if ($parentBasename === $basename) {
            return null;
        }

        return $dir . '/' . $parentBasename . '.' . $ext;
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($path);
    }
}

/**
 * Result of a single cleanup task.
 */
class TaskResult
{
    private string $name;
    private int $total = 0;
    private int $success = 0;
    private int $spaceFreed = 0;
    private array $skipped = [];
    private array $errors = [];
    private ?string $failureReason = null;
    private float $startTime;
    private float $duration = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->startTime = microtime(true);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function incrementTotal(): void
    {
        $this->total++;
    }

    public function incrementSuccess(): void
    {
        $this->success++;
    }

    public function incrementSkipped(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function addSpaceFreed(int $bytes): void
    {
        $this->spaceFreed += $bytes;
    }

    public function addError(int|string $identifier, string $message): void
    {
        $this->errors[$identifier] = $message;
    }

    public function setFailed(string $reason): void
    {
        $this->failureReason = $reason;
    }

    public function finish(): void
    {
        $this->duration = microtime(true) - $this->startTime;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getSuccess(): int
    {
        return $this->success;
    }

    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getTotalSkipped(): int
    {
        return array_sum($this->skipped);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getSpaceFreed(): int
    {
        return $this->spaceFreed;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function isFailed(): bool
    {
        return $this->failureReason !== null;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'total' => $this->total,
            'success' => $this->success,
            'skipped' => $this->skipped,
            'errors' => count($this->errors),
            'space_freed' => $this->spaceFreed,
            'duration' => round($this->duration, 3),
            'failed' => $this->failureReason,
        ];
    }
}

/**
 * Overall cleanup report.
 */
class CleanupReport
{
    /** @var TaskResult[] */
    private array $tasks = [];
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function addTask(string $name, TaskResult $result): void
    {
        $this->tasks[$name] = $result;
    }

    public function getTask(string $name): ?TaskResult
    {
        return $this->tasks[$name] ?? null;
    }

    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function getTotalFilesRemoved(): int
    {
        return array_sum(array_map(fn($t) => $t->getSuccess(), $this->tasks));
    }

    public function getTotalSpaceFreed(): int
    {
        return array_sum(array_map(fn($t) => $t->getSpaceFreed(), $this->tasks));
    }

    public function getHumanSpaceFreed(): string
    {
        $bytes = $this->getTotalSpaceFreed();
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2) . ' ' . $units[$unit];
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function hasErrors(): bool
    {
        foreach ($this->tasks as $task) {
            if (!empty($task->getErrors()) || $task->isFailed()) {
                return true;
            }
        }
        return false;
    }

    public function toArray(): array
    {
        return [
            'tasks' => array_map(fn($t) => $t->toArray(), $this->tasks),
            'summary' => [
                'total_files_removed' => $this->getTotalFilesRemoved(),
                'total_space_freed' => $this->getTotalSpaceFreed(),
                'human_space_freed' => $this->getHumanSpaceFreed(),
                'duration' => round($this->getDuration(), 3),
                'has_errors' => $this->hasErrors(),
            ],
        ];
    }
}
