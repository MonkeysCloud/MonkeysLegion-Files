<?php

namespace MonkeysLegion\Files\Contracts;

use Psr\Http\Message\StreamInterface;

interface FileStorage
{
    public function name(): string;

    /** Store given stream at path. Return public URL or null for private. */
    public function put(string $path, StreamInterface $stream, array $options = []): ?string;

    public function delete(string $path): void;

    public function read(string $path): StreamInterface;

    public function exists(string $path): bool;
}
