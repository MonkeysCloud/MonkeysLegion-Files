<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\RateLimit;

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Files\Exception\RateLimitException;

/**
 * Rate limiter for file uploads using MonkeysLegion-Cache.
 * 
 * Prevents abuse by limiting:
 * - Uploads per time window
 * - Bandwidth per time window
 * - Concurrent uploads
 */
final class UploadRateLimiter
{
    private const CACHE_PREFIX = 'ml_files_ratelimit:';

    public function __construct(
        private CacheManager $cache,
        private int $maxUploadsPerMinute = 10,
        private int $maxBytesPerHour = 104857600, // 100MB
        private int $maxConcurrentUploads = 3,
        private ?string $store = null, // null = default store
    ) {}

    /**
     * Check if the identifier can perform an upload.
     *
     * @throws RateLimitException if any limit is exceeded
     */
    public function check(string $identifier): void
    {
        $this->checkUploadCount($identifier);
        $this->checkBandwidth($identifier);
        $this->checkConcurrent($identifier);
    }

    /**
     * Record a completed upload.
     */
    public function recordUpload(string $identifier, int $bytes): void
    {
        $this->incrementUploadCount($identifier);
        $this->addBandwidth($identifier, $bytes);
    }

    /**
     * Mark the start of an upload (for concurrent tracking).
     */
    public function startUpload(string $identifier): void
    {
        $key = $this->getConcurrentKey($identifier);
        $current = $this->getConcurrentCount($identifier);
        $this->getStore()->set($key, $current + 1, 300); // 5 minute window
    }

    /**
     * Mark the end of an upload (for concurrent tracking).
     */
    public function endUpload(string $identifier): void
    {
        $key = $this->getConcurrentKey($identifier);
        $current = $this->getConcurrentCount($identifier);
        $store = $this->getStore();
        if (!is_object($store)) {
            return;
        }
        /** @var object $store */
        $store->set($key, max(0, $current - 1), 300);
    }

    /**
     * Get current rate limit status for an identifier.
     */
    public function getStatus(string $identifier): array
    {
        $uploadCount = $this->getUploadCount($identifier);
        $bandwidthUsed = $this->getBandwidthUsed($identifier);
        $concurrentCount = $this->getConcurrentCount($identifier);

        return [
            'uploads' => [
                'current' => $uploadCount,
                'limit' => $this->maxUploadsPerMinute,
                'window' => '1 minute',
                'remaining' => max(0, $this->maxUploadsPerMinute - $uploadCount),
            ],
            'bandwidth' => [
                'current' => $bandwidthUsed,
                'current_formatted' => $this->formatBytes($bandwidthUsed),
                'limit' => $this->maxBytesPerHour,
                'limit_formatted' => $this->formatBytes($this->maxBytesPerHour),
                'window' => '1 hour',
                'remaining' => max(0, $this->maxBytesPerHour - $bandwidthUsed),
                'remaining_formatted' => $this->formatBytes(max(0, $this->maxBytesPerHour - $bandwidthUsed)),
            ],
            'concurrent' => [
                'current' => $concurrentCount,
                'limit' => $this->maxConcurrentUploads,
                'remaining' => max(0, $this->maxConcurrentUploads - $concurrentCount),
            ],
        ];
    }

    /**
     * Reset all rate limits for an identifier.
     */
    public function reset(string $identifier): void
    {
        $store = $this->getStore();
        $store->delete($this->getCountKey($identifier));
        $store->delete($this->getBandwidthKey($identifier));
        $store->delete($this->getConcurrentKey($identifier));
    }

    /**
     * Get remaining allowed uploads.
     */
    public function getRemainingUploads(string $identifier): int
    {
        return max(0, $this->maxUploadsPerMinute - $this->getUploadCount($identifier));
    }

    /**
     * Get remaining allowed bandwidth.
     */
    public function getRemainingBandwidth(string $identifier): int
    {
        return max(0, $this->maxBytesPerHour - $this->getBandwidthUsed($identifier));
    }

    /**
     * Check if an upload of given size would exceed limits.
     */
    public function canUpload(string $identifier, int $fileSize): bool
    {
        if ($this->getUploadCount($identifier) >= $this->maxUploadsPerMinute) {
            return false;
        }

        if ($this->getBandwidthUsed($identifier) + $fileSize > $this->maxBytesPerHour) {
            return false;
        }

        if ($this->getConcurrentCount($identifier) >= $this->maxConcurrentUploads) {
            return false;
        }

        return true;
    }

    /**
     * Check upload count limit.
     */
    private function checkUploadCount(string $identifier): void
    {
        $count = $this->getUploadCount($identifier);

        if ($count >= $this->maxUploadsPerMinute) {
            throw new RateLimitException(
                message: sprintf(
                    "Upload rate limit exceeded. Maximum %d uploads per minute. Please try again later.",
                    $this->maxUploadsPerMinute
                ),
                retryAfter: 60,
                limitType: 'uploads_per_minute'
            );
        }
    }

    /**
     * Check bandwidth limit.
     */
    private function checkBandwidth(string $identifier): void
    {
        $bandwidth = $this->getBandwidthUsed($identifier);

        if ($bandwidth >= $this->maxBytesPerHour) {
            throw new RateLimitException(
                message: sprintf(
                    "Bandwidth limit exceeded. Maximum %s per hour. Please try again later.",
                    $this->formatBytes($this->maxBytesPerHour)
                ),
                retryAfter: 3600,
                limitType: 'bandwidth_per_hour'
            );
        }
    }

    /**
     * Check concurrent uploads limit.
     */
    private function checkConcurrent(string $identifier): void
    {
        $current = $this->getConcurrentCount($identifier);

        if ($current >= $this->maxConcurrentUploads) {
            throw new RateLimitException(
                message: sprintf(
                    "Too many concurrent uploads. Maximum %d simultaneous uploads allowed.",
                    $this->maxConcurrentUploads
                ),
                retryAfter: 30,
                limitType: 'concurrent_uploads'
            );
        }
    }

    /**
     * Get current upload count.
     */
    private function getUploadCount(string $identifier): int
    {
        $key = $this->getCountKey($identifier);
        return (int) ($this->getStore()->get($key) ?? 0);
    }

    /**
     * Increment upload count.
     */
    private function incrementUploadCount(string $identifier): void
    {
        $key = $this->getCountKey($identifier);
        $store = $this->getStore();
        
        // Try to use atomic increment if available
        if (method_exists($store, 'increment')) {
            $store->increment($key, 1);
            return;
        }
        
        // Fallback to get/set
        $count = $this->getUploadCount($identifier);
        $store->set($key, $count + 1, 60); // 1 minute window
    }

    /**
     * Get bandwidth used.
     */
    private function getBandwidthUsed(string $identifier): int
    {
        $key = $this->getBandwidthKey($identifier);
        return (int) ($this->getStore()->get($key) ?? 0);
    }

    /**
     * Add to bandwidth used.
     */
    private function addBandwidth(string $identifier, int $bytes): void
    {
        $key = $this->getBandwidthKey($identifier);
        $store = $this->getStore();
        
        // Try to use atomic increment if available
        if (method_exists($store, 'increment')) {
            $current = $this->getBandwidthUsed($identifier);
            if ($current === 0) {
                if (method_exists($store, 'set')) {
                    $store->set($key, $bytes, 3600); // 1 hour window
                }
            } else {
                $store->increment($key, $bytes);
            }
            return;
        }
        
        // Fallback to get/set
        $current = $this->getBandwidthUsed($identifier);
        $store->set($key, $current + $bytes, 3600); // 1 hour window
    }

    /**
     * Get concurrent upload count.
     */
    private function getConcurrentCount(string $identifier): int
    {
        $key = $this->getConcurrentKey($identifier);
        return (int) ($this->getStore()->get($key) ?? 0);
    }

    /**
     * Get the cache store to use.
     */
    private function getStore(): mixed
    {
        if ($this->store !== null) {
            return $this->cache->store($this->store);
        }
        
        return $this->cache;
    }

    /**
     * Get upload count cache key.
     */
    private function getCountKey(string $identifier): string
    {
        return self::CACHE_PREFIX . "count:{$identifier}";
    }

    /**
     * Get bandwidth cache key.
     */
    private function getBandwidthKey(string $identifier): string
    {
        return self::CACHE_PREFIX . "bandwidth:{$identifier}";
    }

    /**
     * Get concurrent uploads cache key.
     */
    private function getConcurrentKey(string $identifier): string
    {
        return self::CACHE_PREFIX . "concurrent:{$identifier}";
    }

    /**
     * Format bytes to human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = $bytes;
        
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        
        return round($value, 2) . ' ' . $units[$i];
    }
}
