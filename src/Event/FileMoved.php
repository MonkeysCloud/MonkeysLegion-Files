<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Event;

use DateTimeImmutable;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Dispatched when a file is moved (within or across disks).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class FileMoved
{
    public function __construct(
        public string $sourcePath,
        public string $destinationPath,
        public string $sourceDisk,
        public string $destinationDisk,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
