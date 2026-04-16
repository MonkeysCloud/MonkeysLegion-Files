<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Cdn;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Signed URL value object with expiration tracking.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SignedUrl
{
    /** Whether the URL has expired. */
    public bool $isExpired {
        get => $this->expiresAt < new \DateTimeImmutable();
    }

    /** Seconds remaining until expiration. */
    public int $ttl {
        get => max(0, $this->expiresAt->getTimestamp() - time());
    }

    public function __construct(
        public readonly string $url,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly string $path = '',
        public readonly string $disk = '',
    ) {}

    public function __toString(): string
    {
        return $this->url;
    }
}
