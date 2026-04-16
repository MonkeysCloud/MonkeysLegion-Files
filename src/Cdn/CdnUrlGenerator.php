<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Cdn;

use MonkeysLegion\Cache\CacheStoreInterface;
use MonkeysLegion\Files\Contracts\CloudStorageInterface;
use MonkeysLegion\Files\Contracts\StorageInterface;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Generates CDN-aware URLs for stored files. Supports custom CDN
 * domains, signed URLs, and cache-busting via checksums.
 *
 * Integrates ML Cache 2.0 for versioned URL caching.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class CdnUrlGenerator
{
    /** Default versioned URL cache TTL: 10 minutes. */
    private const int VERSION_CACHE_TTL = 600;

    public function __construct(
        private readonly ?string $cdnBaseUrl = null,
        private readonly ?CacheStoreInterface $cache = null,
        private readonly int $versionCacheTtl = self::VERSION_CACHE_TTL,
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
     *
     * Uses ML Cache 2.0 `remember()` to cache the versioned URL,
     * avoiding repeated checksum computation on hot paths.
     */
    public function versionedUrl(StorageInterface $driver, string $path): string
    {
        if ($this->cache !== null) {
            $safePath = str_replace(['/', '\\', '{', '}', '(', ')', '@', ':'], '_', $path);
            $cacheKey = 'ml_cdn.versioned.' . $driver->getDriver() . '.' . $safePath;

            return $this->cache->remember(
                $cacheKey,
                $this->versionCacheTtl,
                fn () => $this->buildVersionedUrl($driver, $path),
            );
        }

        return $this->buildVersionedUrl($driver, $path);
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

    /**
     * Invalidate the cached versioned URL for a file.
     *
     * Call this after a file is updated to bust the cache.
     */
    public function invalidateVersionedUrl(StorageInterface $driver, string $path): void
    {
        $safePath = str_replace(['/', '\\', '{', '}', '(', ')', '@', ':'], '_', $path);
        $this->cache?->delete('ml_cdn.versioned.' . $driver->getDriver() . '.' . $safePath);
    }

    /**
     * Build a versioned URL (uncached).
     */
    private function buildVersionedUrl(StorageInterface $driver, string $path): string
    {
        $base  = $this->url($driver, $path);
        $hash  = $driver->checksum($path, 'md5');
        $short = $hash !== null ? substr($hash, 0, 8) : (string) time();

        return $base . '?v=' . $short;
    }
}
