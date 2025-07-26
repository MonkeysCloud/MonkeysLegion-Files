<?php

namespace MonkeysLegion\Files\DTO;

final class FileMeta
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $url,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int    $size,
        public readonly string $sha256,
    ) {}
}
