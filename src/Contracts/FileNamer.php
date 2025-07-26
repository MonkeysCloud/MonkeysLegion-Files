<?php

namespace MonkeysLegion\Files\Contracts;

/**
 * Interface FileNamer
 *
 * This interface defines a method for generating a relative storage path for files.
 * Implementations should provide a way to create unique paths based on the original file name,
 * MIME type, and SHA-256 hash of the file content.
 */
interface FileNamer
{
    /**
     * Produce a relative storage path without a leading slash.
     *
     * @param string $originalName The original name of the file.
     * @param string $mime The MIME type of the file.
     * @param string $sha256 The SHA-256 hash of the file content.
     * @return string The generated path for the file.
     */
    public function path(string $originalName, string $mime, string $sha256): string;
}
