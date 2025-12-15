<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * File already exists when trying to create.
 */
class FileAlreadyExistsException extends StorageException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct(
            message: "File already exists: {$path}",
            previous: $previous,
            context: ['path' => $path]
        );
    }
}
