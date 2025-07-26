<?php

namespace MonkeysLegion\Files\DTO;

/**
 * File metadata DTO.
 *
 * @property string $disk         The storage disk name.
 * @property string $path         The file path relative to the disk root.
 * @property ?string $url         The public URL of the file, or null if private.
 * @property string $originalName The original name of the file.
 * @property string $mimeType     The MIME type of the file.
 * @property int    $size         The size of the file in bytes.
 * @property string $sha256       The SHA-256 hash of the file content.
 */
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
