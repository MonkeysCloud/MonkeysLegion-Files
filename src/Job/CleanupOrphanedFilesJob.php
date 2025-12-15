<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Job;

/**
 * Job for cleaning up orphaned files.
 */
final class CleanupOrphanedFilesJob extends AbstractFileJob
{
    public function __construct(
        private int $olderThanHours = 24,
        private bool $dryRun = false
    ) {
        $this->queue = 'maintenance';
    }

    public function getName(): string
    {
        return 'cleanup_orphaned_files';
    }

    public function handle(): bool
    {
        /** @var \MonkeysLegion\Files\Maintenance\GarbageCollector $gc */
        $gc = $this->resolveGarbageCollector();
        
        $result = $gc->cleanupOrphanedFiles();

        return !$result->isFailed();
    }

    public function toArray(): array
    {
        return [
            'older_than_hours' => $this->olderThanHours,
            'dry_run' => $this->dryRun,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            $data['older_than_hours'] ?? 24,
            $data['dry_run'] ?? false
        );
    }

    private function resolveGarbageCollector(): object
    {
        throw new \RuntimeException('GarbageCollector must be injected via DI container');
    }
}
