<?php
namespace MonkeysLegion\Files\Upload;

use Dflydev\ApacheMimeTypes\PhpRepository;
use MonkeysLegion\Files\Contracts\FileNamer;

/**
 * Generates a path based on the SHA256 hash of the file.
 * The path is structured as: YYYY/MM/DD/sha256.ext
 * where ext is derived from the original file name or MIME type.
 */
final class HashPathNamer implements FileNamer
{
    /**
     * Generate a relative storage path without leading slash.
     *
     * @param string $originalName The original name of the file.
     * @param string $mime The MIME type of the file.
     * @param string $sha256 The SHA256 hash of the file.
     * @return string The generated path in the format: YYYY/MM/DD/sha256.ext
     * @throws \DateMalformedStringException
     */
    public function path(string $originalName, string $mime, string $sha256): string
    {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION)
            ?: $this->extFromMime($mime);

        $date = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $slug = substr($sha256, 0, 16);

        return sprintf(
            '%s/%s/%s/%s.%s',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $slug,
            $ext
        );
    }

    /**
     * Get the file extension from the MIME type.
     *
     * @param string $mime The MIME type of the file.
     * @return string The file extension, or 'bin' if none found.
     */
    private function extFromMime(string $mime): string
    {
        static $repo;
        if ($repo === null) {
            $repo = new PhpRepository();
        }

        // This returns an array of extensions, e.g. ['jpg','jpeg']
        $exts = $repo->findExtensions($mime);

        // Pick the first, or default to 'bin'
        return $exts[0] ?? 'bin';
    }
}