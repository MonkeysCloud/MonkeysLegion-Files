<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Permission denied for storage operation.
 */
class PermissionDeniedException extends StorageException
{
    public function __construct(string $path, string $operation, ?\Throwable $previous = null)
    {
        parent::__construct(
            message: "Permission denied for {$operation} on: {$path}",
            previous: $previous,
            context: ['path' => $path, 'operation' => $operation]
        );
    }
}
