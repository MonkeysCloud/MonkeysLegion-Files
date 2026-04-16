<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Supported image processing drivers.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum ImageDriver: string
{
    case Gd      = 'gd';
    case Imagick = 'imagick';

    public function isAvailable(): bool
    {
        return extension_loaded($this->value);
    }
}
