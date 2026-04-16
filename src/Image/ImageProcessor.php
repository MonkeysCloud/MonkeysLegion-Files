<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

use MonkeysLegion\Files\Contracts\ImageProcessorInterface;
use MonkeysLegion\Files\Exception\ImageProcessingException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Image processing with GD/Imagick support, named conversions,
 * WebP/AVIF output, and PHP 8.4 property hooks.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ImageProcessor implements ImageProcessorInterface
{
    private readonly ConversionRegistry $registry;

    /** The active driver name. */
    public string $driverName {
        get => $this->driver->value;
    }

    /** Whether the current driver extension is loaded. */
    public bool $isAvailable {
        get => $this->driver->isAvailable();
    }

    /** Supported output formats for the active driver. */
    public array $supportedFormats {
        get => match ($this->driver) {
            ImageDriver::Imagick => ImageFormat::cases(),
            ImageDriver::Gd     => array_filter(
                ImageFormat::cases(),
                static fn(ImageFormat $f) => match ($f) {
                    ImageFormat::Avif => function_exists('imageavif'),
                    ImageFormat::Webp => function_exists('imagewebp'),
                    default           => true,
                },
            ),
        };
    }

    public function __construct(
        private readonly ImageDriver $driver = ImageDriver::Gd,
        private readonly int $defaultQuality = 85,
        ?ConversionRegistry $registry = null,
    ) {
        if (!$this->driver->isAvailable()) {
            throw new ImageProcessingException(
                "Image driver '{$this->driver->value}' is not available",
            );
        }

        $this->registry = $registry ?? new ConversionRegistry();
    }

    // ── ImageProcessorInterface ──────────────────────────────────

    public function thumbnail(string $sourcePath, int $width, int $height, string $fit = 'cover'): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->resize($width, $height, $fit);

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    public function resize(string $sourcePath, int $width, int $height): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->resize($width, $height, 'contain');

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    public function crop(string $sourcePath, int $width, int $height, int $x = 0, int $y = 0): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->crop($width, $height, $x, $y);

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    public function convert(string $sourcePath, string $format, int $quality = 85): string
    {
        $manipulator = $this->load($sourcePath);

        return $manipulator->encode($format, $quality);
    }

    public function applyConversion(string $sourcePath, Conversion $conversion): string
    {
        $manipulator = $this->load($sourcePath);

        if ($conversion->width !== null && $conversion->height !== null) {
            $manipulator->resize($conversion->width, $conversion->height, $conversion->fit);
        }

        if ($conversion->stripMetadata) {
            $manipulator->stripMetadata();
        }

        $format = $conversion->format?->extension() ?? null;

        return $manipulator->encode($format, $conversion->quality);
    }

    public function getDimensions(string $sourcePath): array
    {
        return $this->load($sourcePath)->getDimensions();
    }

    public function optimize(string $sourcePath, int $quality = 85): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->stripMetadata();

        return $manipulator->encode(quality: $quality);
    }

    // ── Extended Operations ──────────────────────────────────────

    /**
     * Apply a watermark overlay.
     *
     * @param string $sourcePath    Source image path
     * @param string $watermarkData Watermark binary data
     * @param string $position      Position: 'top-left', 'center', 'bottom-right', etc.
     * @param int    $opacity       Watermark opacity (0–100)
     * @param int    $padding       Edge padding in pixels
     */
    public function watermark(
        string $sourcePath,
        string $watermarkData,
        string $position = 'bottom-right',
        int $opacity = 100,
        int $padding = 10,
    ): string {
        $manipulator = $this->load($sourcePath);
        $manipulator->watermark($watermarkData, $position, $opacity, $padding);

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    /** Rotate an image by degrees. */
    public function rotate(string $sourcePath, float $degrees): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->rotate($degrees);

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    /** Apply blur effect. */
    public function blur(string $sourcePath, int $amount = 5): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->blur($amount);

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    /** Apply grayscale filter. */
    public function grayscale(string $sourcePath): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->grayscale();

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    /** Auto-orient based on EXIF data. */
    public function autoOrient(string $sourcePath): string
    {
        $manipulator = $this->load($sourcePath);
        $manipulator->autoOrient();

        return $manipulator->encode(quality: $this->defaultQuality);
    }

    // ── Registry Delegation ──────────────────────────────────────

    /**
     * Register a named conversion.
     */
    public function registerConversion(Conversion $conversion): self
    {
        $this->registry->register($conversion);

        return $this;
    }

    /**
     * Process all (or named) registered conversions.
     *
     * @param string       $sourcePath      Source image path
     * @param list<string> $conversionNames Subset to apply (empty = all)
     *
     * @return array<string, string> Map of conversion name => binary image data
     */
    public function processConversions(string $sourcePath, array $conversionNames = []): array
    {
        $results     = [];
        $conversions = $conversionNames !== []
            ? array_filter(
                array_map(fn(string $n) => $this->registry->get($n), $conversionNames),
            )
            : $this->registry->all();

        foreach ($conversions as $conversion) {
            $results[$conversion->name] = $this->applyConversion($sourcePath, $conversion);
        }

        return $results;
    }

    // ── Internal ─────────────────────────────────────────────────

    private function load(string $sourcePath): ImageManipulator
    {
        $data = file_get_contents($sourcePath);

        if ($data === false) {
            throw new ImageProcessingException("Cannot read image: {$sourcePath}");
        }

        return new ImageManipulator($data, $this->driver);
    }
}
