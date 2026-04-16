<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Attributes;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Marks an entity property as a file attachment. The framework
 * auto-resolves the storage disk and path prefix.
 *
 * ```php
 * class User {
 *     #[Storable(disk: 's3', path: 'avatars')]
 *     public ?string $avatar = null;
 * }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Storable
{
    /**
     * @param string      $disk       Target disk name
     * @param string      $path       Directory prefix within the disk
     * @param string|null $collection Optional collection name for grouping
     */
    public function __construct(
        public string $disk = 'local',
        public string $path = '',
        public ?string $collection = null,
    ) {}
}
