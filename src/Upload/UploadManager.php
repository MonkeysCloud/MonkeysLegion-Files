<?php

namespace MonkeysLegion\Files\Upload;

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileNamer;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\DTO\FileMeta;
use MonkeysLegion\Files\Validation\UploadRules;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UploadManager
{
    /** @var callable|null */
    private $onAfterSave;

    /**
     * @param string[]         $mimeAllow
     * @param callable|null    $onAfterSave  Optional hook; receives FileMeta after save.
     */
    public function __construct(
        private FileStorage $storage,
        private FileNamer   $namer,
        private int         $maxBytes,
        private array       $mimeAllow = [],
                            $onAfterSave = null
    ) {
        if ($onAfterSave !== null && ! is_callable($onAfterSave)) {
            throw new \InvalidArgumentException('UploadManager::$onAfterSave must be callable or null.');
        }
        $this->onAfterSave = $onAfterSave;
    }

    public function handle(Request $request, string $field = 'file'): FileMeta
    {
        $files = $request->getUploadedFiles();
        if (! isset($files[$field])) {
            throw new \RuntimeException("No file in field '{$field}'.");
        }

        $file = $files[$field];
        UploadRules::validate($file, $this->maxBytes, $this->mimeAllow);

        $in = $file->getStream();
        $tmp = fopen('php://temp', 'w+b');
        $ctx = hash_init('sha256');
        while (! $in->eof()) {
            $chunk = $in->read(8192);
            hash_update($ctx, $chunk);
            fwrite($tmp, $chunk);
        }
        $sha256 = hash_final($ctx);
        rewind($tmp);

        $mime = $file->getClientMediaType() ?: 'application/octet-stream';
        $name = $file->getClientFilename() ?: 'file';
        $path = $this->namer->path($name, $mime, $sha256);

        $url = $this->storage->put($path, Psr7::streamFor($tmp), ['mime' => $mime]);

        $meta = new FileMeta(
            disk:         $this->storage->name(),
            path:         $path,
            url:          $url,
            originalName: $name,
            mimeType:     $mime,
            size:         $file->getSize(),
            sha256:       $sha256,
        );

        if ($this->onAfterSave) {
            ($this->onAfterSave)($meta);
        }

        return $meta;
    }
}