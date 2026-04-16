<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Maintenance;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Result of a garbage collection run.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class GarbageCollectionResult
{
    /** Human-readable freed space. */
    public string $humanFreed {
        get => match (true) {
            $this->freedBytes >= 1_073_741_824 => round($this->freedBytes / 1_073_741_824, 2) . ' GB',
            $this->freedBytes >= 1_048_576     => round($this->freedBytes / 1_048_576, 2) . ' MB',
            $this->freedBytes >= 1_024         => round($this->freedBytes / 1_024, 2) . ' KB',
            default                            => $this->freedBytes . ' B',
        };
    }

    /** Whether orphans were found. */
    public bool $hasOrphans {
        get => $this->orphans > 0;
    }

    public function __construct(
        public readonly int $scanned,
        public readonly int $orphans,
        public readonly int $deleted,
        public readonly int $freedBytes,
        public readonly bool $dryRun,
        /** @var list<string> */
        public readonly array $orphanPaths = [],
    ) {}
}
