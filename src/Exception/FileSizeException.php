<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * File size exceeds limit.
 */
class FileSizeException extends UploadException
{
    public function __construct(
        int $size,
        int $maxSize,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: sprintf(
                "File size %s exceeds maximum allowed %s",
                self::formatBytes($size),
                self::formatBytes($maxSize)
            ),
            previous: $previous,
            context: ['size' => $size, 'max_size' => $maxSize]
        );
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
