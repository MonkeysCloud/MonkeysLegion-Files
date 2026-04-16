<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Event;

use DateTimeImmutable;
use MonkeysLegion\Files\Entity\FileRecord;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Dispatched when an upload (including chunked) completes.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class UploadCompleted
{
    public function __construct(
        public FileRecord $file,
        public string $disk,
        public bool $chunked = false,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
