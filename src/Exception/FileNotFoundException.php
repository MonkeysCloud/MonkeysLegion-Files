<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * File not found in storage.
 */
class FileNotFoundException extends StorageException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct(
            message: "File not found: {$path}",
            previous: $previous,
            context: ['path' => $path]
        );
    }
}
