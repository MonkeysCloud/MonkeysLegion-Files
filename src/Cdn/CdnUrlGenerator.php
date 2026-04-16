<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Cdn;

use MonkeysLegion\Files\Contracts\CloudStorageInterface;
use MonkeysLegion\Files\Contracts\StorageInterface;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Generates CDN-aware URLs for stored files. Supports custom CDN
 * domains, signed URLs, and cache-busting via checksums.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class CdnUrlGenerator
{
    public function __construct(
        private readonly ?string $cdnBaseUrl = null,
    ) {}

    /**
     * Generate a public URL for a file.
     *
     * Falls back to the driver's url() if no CDN is configured.
     */
    public function url(StorageInterface $driver, string $path): string
    {
        if ($this->cdnBaseUrl !== null) {
            return rtrim($this->cdnBaseUrl, '/') . '/' . ltrim($path, '/');
        }

        return $driver->url($path);
    }

    /**
     * Generate a cache-busted URL using the file checksum.
     */
    public function versionedUrl(StorageInterface $driver, string $path): string
    {
        $base  = $this->url($driver, $path);
        $hash  = $driver->checksum($path, 'md5');
        $short = $hash !== null ? substr($hash, 0, 8) : (string) time();

        return $base . '?v=' . $short;
    }

    /**
     * Generate a signed/temporary URL.
     *
     * Only works with CloudStorageInterface drivers.
     *
     * @param int $ttlSeconds Time-to-live in seconds
     */
    public function signedUrl(
        StorageInterface $driver,
        string $path,
        int $ttlSeconds = 3600,
    ): SignedUrl {
        $expiresAt = new \DateTimeImmutable("+{$ttlSeconds} seconds");

        if ($driver instanceof CloudStorageInterface) {
            $url = $driver->temporaryUrl($path, $expiresAt);
        } else {
            // For local drivers, just use the public URL (no signing)
            $url = $this->url($driver, $path);
        }

        return new SignedUrl(
            url: $url,
            expiresAt: $expiresAt,
            path: $path,
            disk: $driver->getDriver(),
        );
    }
}
