<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Immutable virus scan result value object.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ScanResult
{
    public bool $hasThreat {
        get => !$this->isClean;
    }

    public function __construct(
        public readonly bool $isClean,
        public readonly ?string $threat = null,
        public readonly string $scanner = 'unknown',
        public readonly float $scanTime = 0.0,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_clean'  => $this->isClean,
            'threat'    => $this->threat,
            'scanner'   => $this->scanner,
            'scan_time' => $this->scanTime,
            'metadata'  => $this->metadata,
        ];
    }
}
