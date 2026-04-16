<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Attributes;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Marks a class as a disk configuration.
 * Used by the DI container to auto-register storage drivers.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Disk
{
    /**
     * @param string $name   Disk name (e.g. 'local', 's3', 'gcs')
     * @param string $driver Driver identifier (e.g. 'local', 's3', 'gcs', 'memory')
     */
    public function __construct(
        public string $name,
        public string $driver = 'local',
    ) {}
}
