<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Job for syncing files to remote storage.
 */
final class SyncToRemoteJob extends AbstractFileJob
{
    public function __construct(
        private string $sourceDisk,
        private string $targetDisk,
        private string $path,
        private bool $deleteSource = false
    ) {
        $this->queue = 'sync';
        $this->maxRetries = 5;
        $this->retryDelay = 120;
    }

    public function getName(): string
    {
        return 'sync_to_remote';
    }

    public function handle(): bool
    {
        /** @var \MonkeysLegion\Files\Contracts\StorageInterface $sourceStorage */
        $sourceStorage = $this->resolveStorage($this->sourceDisk);
        
        /** @var \MonkeysLegion\Files\Contracts\StorageInterface $targetStorage */
        $targetStorage = $this->resolveStorage($this->targetDisk);

        // Get file from source
        $stream = $sourceStorage->getStream($this->path);
        
        if ($stream === null) {
            return false;
        }

        // Upload to target
        $success = $targetStorage->putStream($this->path, $stream);
        
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$success) {
            return false;
        }

        // Delete source if requested
        if ($this->deleteSource) {
            $sourceStorage->delete($this->path);
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'source_disk' => $this->sourceDisk,
            'target_disk' => $this->targetDisk,
            'path' => $this->path,
            'delete_source' => $this->deleteSource,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['source_disk'],
            $data['target_disk'],
            $data['path'],
            $data['delete_source'] ?? false
        );
    }

    private function resolveStorage(string $disk): object
    {
        throw new \RuntimeException('Storage must be injected via DI container');
    }
}
