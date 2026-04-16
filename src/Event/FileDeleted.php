<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Event;

use DateTimeImmutable;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Dispatched when a file is deleted from a disk.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class FileDeleted
{
    public function __construct(
        public string $path,
        public string $disk,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
