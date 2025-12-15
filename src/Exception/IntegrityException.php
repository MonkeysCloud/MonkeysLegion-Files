<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Checksum/integrity verification failure.
 */
class IntegrityException extends FilesException
{
    public function __construct(
        string $path,
        string $expectedChecksum,
        string $actualChecksum,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: "File integrity check failed for: {$path}",
            previous: $previous,
            context: [
                'path' => $path,
                'expected' => $expectedChecksum,
                'actual' => $actualChecksum,
            ]
        );
    }
}
