<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Image processing failures.
 */
class ImageProcessingException extends FilesException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        public readonly ?string $operation = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
            context: array_filter([
                'path' => $path,
                'operation' => $operation,
            ])
        );
    }
}
