<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Named conversion value object. Defines a single image transformation
 * (resize, crop, format convert) that can be registered and reused.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Conversion
{
    /** Computed output extension. */
    public string $outputExtension {
        get => $this->format?->extension() ?? 'jpg';
    }

    /** Computed output MIME type. */
    public string $outputMimeType {
        get => $this->format?->mimeType() ?? 'image/jpeg';
    }

    public function __construct(
        public readonly string $name,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $fit = 'cover',
        public readonly ?ImageFormat $format = null,
        public readonly int $quality = 85,
        public readonly bool $stripMetadata = true,
    ) {}

    /**
     * Create a thumbnail conversion.
     */
    public static function thumbnail(string $name, int $width, int $height): self
    {
        return new self(name: $name, width: $width, height: $height, fit: 'cover');
    }

    /**
     * Create a format conversion (e.g. to WebP).
     */
    public static function toFormat(string $name, ImageFormat $format, int $quality = 85): self
    {
        return new self(name: $name, format: $format, quality: $quality);
    }

    /**
     * Create a resize conversion with aspect ratio preservation.
     */
    public static function resize(string $name, int $maxWidth, int $maxHeight): self
    {
        return new self(name: $name, width: $maxWidth, height: $maxHeight, fit: 'contain');
    }
}
