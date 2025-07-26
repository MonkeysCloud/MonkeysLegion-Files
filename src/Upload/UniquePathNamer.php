<?php

namespace MonkeysLegion\Files\Upload;

use MonkeysLegion\Files\Contracts\FileNamer;
use Dflydev\ApacheMimeTypes\PhpRepository;
use Random\RandomException;

/**
 * Generates a unique path for uploaded files using a UUID and current date.
 * The path format is: YYYY/MM/DD/uuid.ext
 * The extension is guessed via dflydev/apache-mime-types repository or original name.
 */
final class UniquePathNamer implements FileNamer
{
    private PhpRepository $mimeRepo;

    public function __construct()
    {
        $this->mimeRepo = new PhpRepository();
    }

    /**
     * Produce a relative storage path without leading slash.
     *
     * @param string $originalName The original name of the file.
     * @param string $mime The MIME type of the file.
     * @param string $sha256 The SHA-256 hash of the file content (not used here).
     * @return string The generated path in the format: YYYY/MM/DD/uuid.ext
     * @throws RandomException
     */
    public function path(string $originalName, string $mime, string $sha256): string
    {
        $ext = $this->guessExt($originalName, $mime) ?: 'bin';

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $uuid = $this->uuidV4();

        return sprintf('%s/%s/%s/%s.%s',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $uuid,
            $ext
        );
    }

    /**
     * Generate a version 4 UUID.
     *
     * @return string The generated UUID.
     * @throws RandomException
     */
    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    /**
     * Guess the file extension based on the original name or MIME type.
     *
     * @param string $originalName
     * @param string $mime
     * @return string|null
     */
    private function guessExt(string $originalName, string $mime): ?string
    {
        // Try original filename extension
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        if ($ext) {
            return strtolower($ext);
        }

        // Lookup via ApacheMimeTypes repository
        $exts = $this->mimeRepo->findExtensions($mime);
        return isset($exts[0]) ? strtolower($exts[0]) : null;
    }
}
