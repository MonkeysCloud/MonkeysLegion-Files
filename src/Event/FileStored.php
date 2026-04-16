<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Event;

use DateTimeImmutable;
use MonkeysLegion\Files\Entity\FileRecord;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Dispatched when a file is stored to any disk.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class FileStored
{
    public function __construct(
        public FileRecord $file,
        public string $disk,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
