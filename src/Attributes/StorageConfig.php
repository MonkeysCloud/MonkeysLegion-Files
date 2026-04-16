<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Attributes;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Marks a class as the storage configuration provider.
 * The DI container uses this to discover disk definitions.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class StorageConfig
{
    /**
     * @param string $defaultDisk The default disk name
     */
    public function __construct(
        public string $defaultDisk = 'local',
    ) {}
}
