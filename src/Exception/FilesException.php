<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Base exception for all file-related errors.
 */
class FilesException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
