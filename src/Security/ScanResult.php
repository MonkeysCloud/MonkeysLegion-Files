<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Security;

/**
 * Result of a virus scan.
 */
class ScanResult
{
    public function __construct(
        public readonly bool $isClean,
        public readonly ?string $threat = null,
        public readonly string $scanner = 'unknown',
        public readonly float $scanTime = 0.0,
        public readonly array $metadata = []
    ) {}

    public function hasThreat(): bool
    {
        return !$this->isClean;
    }

    public function toArray(): array
    {
        return [
            'is_clean' => $this->isClean,
            'threat' => $this->threat,
            'scanner' => $this->scanner,
            'scan_time' => $this->scanTime,
            'metadata' => $this->metadata,
        ];
    }
}
