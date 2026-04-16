<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Maintenance;

use MonkeysLegion\Files\Contracts\StorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Cleans up orphaned files that exist in storage but have no
 * corresponding database record. Supports dry-run mode.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class GarbageCollector
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Scan a storage driver for orphaned files.
     *
     * @param StorageInterface $storage      The storage to scan
     * @param list<string>     $knownPaths   Paths that have database records
     * @param string           $directory    Root directory to scan
     * @param bool             $dryRun       If true, don't actually delete
     *
     * @return GarbageCollectionResult
     */
    public function collect(
        StorageInterface $storage,
        array $knownPaths,
        string $directory = '',
        bool $dryRun = false,
    ): GarbageCollectionResult {
        $allFiles  = $storage->files($directory, recursive: true);
        $knownSet  = array_flip($knownPaths);
        $orphans   = [];
        $totalSize = 0;

        foreach ($allFiles as $filePath) {
            if (!isset($knownSet[$filePath])) {
                $orphans[] = $filePath;
                $totalSize += $storage->size($filePath) ?? 0;
            }
        }

        $deleted = 0;

        if (!$dryRun) {
            foreach ($orphans as $orphanPath) {
                if ($storage->delete($orphanPath)) {
                    $deleted++;
                    $this->logger->info('Deleted orphaned file', ['path' => $orphanPath]);
                }
            }
        }

        $this->logger->info('Garbage collection complete', [
            'scanned'    => count($allFiles),
            'orphans'    => count($orphans),
            'deleted'    => $deleted,
            'freed_bytes' => $totalSize,
            'dry_run'    => $dryRun,
        ]);

        return new GarbageCollectionResult(
            scanned: count($allFiles),
            orphans: count($orphans),
            deleted: $deleted,
            freedBytes: $totalSize,
            dryRun: $dryRun,
            orphanPaths: $orphans,
        );
    }
}
