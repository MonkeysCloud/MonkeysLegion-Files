<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FileNotFoundException extends FilesException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct("File not found: {$path}", 0, $previous);
    }
}
