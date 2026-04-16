<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Upload;

use MonkeysLegion\Files\Exception\ValidationException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Validates uploaded files against configurable rules:
 * max size, allowed MIME types, denied extensions.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class UploadValidator
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param int|null       $maxSize          Maximum file size in bytes (null = no limit)
     * @param list<string>   $allowedMimes     Allowed MIME types (empty = all)
     * @param list<string>   $deniedExtensions Denied file extensions
     */
    public function __construct(
        private readonly ?int $maxSize = null,
        private readonly array $allowedMimes = [],
        private readonly array $deniedExtensions = ['php', 'phar', 'exe', 'sh', 'bat', 'cmd'],
    ) {}

    /**
     * Validate an uploaded file.
     *
     * @throws ValidationException If validation fails
     */
    public function validate(UploadedFile $file): void
    {
        $this->errors = [];

        $this->checkSize($file);
        $this->checkMime($file);
        $this->checkExtension($file);

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }

    /**
     * Check if a file passes validation without throwing.
     */
    public function passes(UploadedFile $file): bool
    {
        try {
            $this->validate($file);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function checkSize(UploadedFile $file): void
    {
        if ($this->maxSize !== null && $file->size > $this->maxSize) {
            $this->errors[] = sprintf(
                'File size (%s) exceeds maximum (%s)',
                $this->formatBytes($file->size),
                $this->formatBytes($this->maxSize),
            );
        }
    }

    private function checkMime(UploadedFile $file): void
    {
        if ($this->allowedMimes !== [] && !in_array($file->mimeType, $this->allowedMimes, true)) {
            $this->errors[] = sprintf(
                "MIME type '%s' is not allowed. Accepted: %s",
                $file->mimeType,
                implode(', ', $this->allowedMimes),
            );
        }
    }

    private function checkExtension(UploadedFile $file): void
    {
        if (in_array($file->extension, $this->deniedExtensions, true)) {
            $this->errors[] = sprintf(
                "Extension '%s' is not allowed",
                $file->extension,
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 2) . ' GB',
            $bytes >= 1_048_576     => round($bytes / 1_048_576, 2) . ' MB',
            $bytes >= 1_024         => round($bytes / 1_024, 2) . ' KB',
            default                 => $bytes . ' B',
        };
    }
}
