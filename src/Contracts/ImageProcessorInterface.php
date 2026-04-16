<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

use MonkeysLegion\Files\Image\Conversion;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Contract for image processing implementations.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface ImageProcessorInterface
{
    /**
     * Create a thumbnail.
     *
     * @param string $sourcePath Absolute path to the source image
     * @param int    $width      Target width
     * @param int    $height     Target height
     * @param string $fit        Fit mode: 'cover', 'contain', 'stretch', 'fit'
     *
     * @return string Binary image data
     */
    public function thumbnail(string $sourcePath, int $width, int $height, string $fit = 'cover'): string;

    /**
     * Resize an image.
     *
     * @return string Binary image data
     */
    public function resize(string $sourcePath, int $width, int $height): string;

    /**
     * Crop an image.
     *
     * @return string Binary image data
     */
    public function crop(string $sourcePath, int $width, int $height, int $x = 0, int $y = 0): string;

    /**
     * Convert image to a different format.
     *
     * @param string $sourcePath  Absolute path to the source image
     * @param string $format      Target format (jpg, png, webp, avif)
     * @param int    $quality     Output quality (0–100)
     *
     * @return string Binary image data
     */
    public function convert(string $sourcePath, string $format, int $quality = 85): string;

    /**
     * Apply a named conversion.
     *
     * @return string Binary image data
     */
    public function applyConversion(string $sourcePath, Conversion $conversion): string;

    /**
     * Get image dimensions.
     *
     * @return array{width: int, height: int}
     */
    public function getDimensions(string $sourcePath): array;

    /**
     * Optimize an image (strip metadata, recompress).
     *
     * @return string Binary image data
     */
    public function optimize(string $sourcePath, int $quality = 85): string;
}
