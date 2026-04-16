<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Thrown when upload or file validation fails.
 * Carries the list of error messages.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ValidationException extends FilesException
{
    /** Number of validation errors. */
    public int $errorCount {
        get => count($this->errors);
    }

    /** Whether there is exactly one error. */
    public bool $isSingleError {
        get => count($this->errors) === 1;
    }

    /** The first error message (convenience). */
    public string $firstError {
        get => $this->errors[0] ?? '';
    }

    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('Validation failed: ' . implode('; ', $errors));
    }
}
