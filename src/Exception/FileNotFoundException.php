<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Thrown when a requested file does not exist.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FileNotFoundException extends FilesException
{
    /** The file path that was not found. */
    public string $filePath {
        get => $this->path;
    }

    public function __construct(
        private readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("File not found: {$path}", 0, $previous);
    }
}
