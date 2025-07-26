<?php
namespace MonkeysLegion\Files\Upload;

use Dflydev\ApacheMimeTypes\PhpRepository;
use MonkeysLegion\Files\Contracts\FileNamer;

final class HashPathNamer implements FileNamer
{
    /**
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