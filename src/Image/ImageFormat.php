<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Supported image output formats.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum ImageFormat: string
{
    case Jpeg = 'jpeg';
    case Png  = 'png';
    case Gif  = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png  => 'image/png',
            self::Gif  => 'image/gif',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png  => 'png',
            self::Gif  => 'gif',
            self::Webp => 'webp',
            self::Avif => 'avif',
        };
    }
}
