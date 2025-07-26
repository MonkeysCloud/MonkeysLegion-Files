<?php

namespace MonkeysLegion\Files\Storage;

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;

final class LocalStorage implements FileStorage
{
    public function __construct(
        private string $root,
        private ?string $publicBaseUrl = null,
    ) {}

    public function name(): string { return 'local'; }

    public function put(string $path, StreamInterface $stream, array $options = []): ?string
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        @mkdir(dirname($abs), 0775, true);
        $fh = fopen($abs, 'wb');
        while (!$stream->eof()) {
            fwrite($fh, $stream->read(8192));
        }
        fclose($fh);
        return $this->publicBaseUrl ? rtrim($this->publicBaseUrl, '/').'/'.$path : null;
    }

    public function delete(string $path): void
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        if (is_file($abs)) @unlink($abs);
    }

    public function read(string $path): StreamInterface
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        $handle = fopen($abs, 'rb');
        return Psr7::streamFor($handle);
    }

    public function exists(string $path): bool
    {
        return is_file(rtrim($this->root, '/').'/'.ltrim($path, '/'));
    }
}
