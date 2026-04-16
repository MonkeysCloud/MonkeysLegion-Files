<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ValidationException extends FilesException
{
    /**
     * @param list<string> $errors Validation errors
     */
    public function __construct(
        public readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            'Upload validation failed: ' . implode('; ', $errors),
            0,
            $previous,
        );
    }
}
