<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\ImageProcessingException;

/**
 * Image processing with support for thumbnails, optimization, watermarks, and format conversion.
 * Supports both GD and Imagick drivers.
 */
final class ImageProcessor
{
    /** @var array<string, callable> */
    private array $conversions = [];



    public function __construct(
        private string $driver = 'gd',
        private int $quality = 85,
    ) {
        $this->validateDriver();
    }

    /**
     * Register a named conversion for batch processing.
     */
    public function registerConversion(string $name, callable $callback): self
    {
        $this->conversions[$name] = $callback;
        return $this;
    }

    /**
     * Process image with registered conversions.
     *
     * @return array<string, string> Map of conversion name to stored path
     */
    public function process(StorageInterface $storage, string $sourcePath, array $conversionNames = []): array
    {
        $sourceData = $storage->get($sourcePath);
        $results = [];

        $conversionsToRun = $conversionNames ?: array_keys($this->conversions);

        foreach ($conversionsToRun as $name) {
            if (!isset($this->conversions[$name])) {
                continue;
            }

            $manipulator = new ImageManipulator($sourceData, $this->driver);
            ($this->conversions[$name])($manipulator);

            $results[$name] = $this->saveManipulator($storage, $manipulator, $sourcePath, $name);
        }

        return $results;
    }

    /**
     * Create a thumbnail with optional fit mode.
     */
    public function thumbnail(
        StorageInterface $storage,
        string $sourcePath,
        int $width,
        int $height,
        string $fit = 'cover'
    ): string {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->resize($width, $height, $fit);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, "thumb_{$width}x{$height}");
    }

    /**
     * Optimize image for web (reduce quality, strip metadata).
     */
    public function optimize(StorageInterface $storage, string $sourcePath, ?int $quality = null): string
    {
        $quality = $quality ?? $this->quality;
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->optimize($quality);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, 'optimized', $quality);
    }

    /**
     * Apply watermark to image.
     */
    public function watermark(
        StorageInterface $storage,
        string $sourcePath,
        string $watermarkPath,
        string $position = 'bottom-right',
        int $opacity = 100,
        int $padding = 10
    ): string {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $watermarkData = $storage->get($watermarkPath);

        $manipulator->watermark($watermarkData, $position, $opacity, $padding);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, 'watermarked');
    }

    /**
     * Convert image to another format.
     */
    public function convert(StorageInterface $storage, string $sourcePath, string $targetFormat): string
    {
        $manipulator = $this->loadImage($storage, $sourcePath);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, $targetFormat, null, $targetFormat);
    }

    /**
     * Crop image to specific dimensions.
     */
    public function crop(
        StorageInterface $storage,
        string $sourcePath,
        int $width,
        int $height,
        int $x = 0,
        int $y = 0
    ): string {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->crop($width, $height, $x, $y);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, "crop_{$width}x{$height}");
    }

    /**
     * Rotate image by degrees.
     */
    public function rotate(StorageInterface $storage, string $sourcePath, float $degrees): string
    {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->rotate($degrees);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, "rotate_{$degrees}");
    }

    /**
     * Apply blur effect.
     */
    public function blur(StorageInterface $storage, string $sourcePath, int $amount = 5): string
    {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->blur($amount);

        return $this->saveManipulator($storage, $manipulator, $sourcePath, "blur_{$amount}");
    }

    /**
     * Apply grayscale filter.
     */
    public function grayscale(StorageInterface $storage, string $sourcePath): string
    {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->grayscale();

        return $this->saveManipulator($storage, $manipulator, $sourcePath, 'grayscale');
    }

    /**
     * Get image dimensions.
     */
    public function getDimensions(StorageInterface $storage, string $sourcePath): array
    {
        $manipulator = $this->loadImage($storage, $sourcePath);
        return $manipulator->getDimensions();
    }

    /**
     * Auto-orient image based on EXIF data.
     */
    public function autoOrient(StorageInterface $storage, string $sourcePath): string
    {
        $manipulator = $this->loadImage($storage, $sourcePath);
        $manipulator->autoOrient();

        return $this->saveManipulator($storage, $manipulator, $sourcePath, 'oriented');
    }

    private function loadImage(StorageInterface $storage, string $path): ImageManipulator
    {
        $data = $storage->get($path);
        return new ImageManipulator($data, $this->driver);
    }

    private function saveManipulator(
        StorageInterface $storage,
        ImageManipulator $manipulator,
        string $originalPath,
        string $suffix,
        ?int $quality = null,
        ?string $format = null
    ): string {
        $quality = $quality ?? $this->quality;
        $pathInfo = pathinfo($originalPath);
        $extension = $format ?? $pathInfo['extension'] ?? 'jpg';

        $newPath = sprintf(
            '%s/%s_%s.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $suffix,
            $extension
        );

        $blob = $manipulator->encode($extension, $quality);

        $mimeType = match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        if (!$storage->put($newPath, $blob, ['mime_type' => $mimeType])) {
            throw new ImageProcessingException("Failed to save processed image: {$newPath}");
        }

        return $newPath;
    }

    private function validateDriver(): void
    {
        if ($this->driver === 'imagick' && !extension_loaded('imagick')) {
            throw new ImageProcessingException("Imagick extension not loaded");
        }

        if ($this->driver === 'gd' && !extension_loaded('gd')) {
            throw new ImageProcessingException("GD extension not loaded");
        }
    }
}

/**
 * Internal class for image manipulation operations.
 */
final class ImageManipulator
{
    private \GdImage|\Imagick $image;
    private string $driver;

    public function __construct(string $data, string $driver = 'gd')
    {
        $this->driver = $driver;
        $this->image = $this->createFromString($data);
    }

    public function resize(int $width, int $height, string $fit = 'cover'): self
    {
        if ($this->image instanceof \Imagick) {
            match ($fit) {
                'contain' => $this->image->thumbnailImage($width, $height, true),
                'cover' => $this->image->cropThumbnailImage($width, $height),
                'stretch' => $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1),
                'fit' => $this->fitImage($width, $height),
                default => $this->image->cropThumbnailImage($width, $height),
            };
        } else {
            $this->resizeGd($width, $height, $fit);
        }

        return $this;
    }

    public function crop(int $width, int $height, int $x = 0, int $y = 0): self
    {
        if ($this->image instanceof \Imagick) {
            $this->image->cropImage($width, $height, $x, $y);
        } else {
            $cropped = imagecreatetruecolor($width, $height);
            imagecopy($cropped, $this->image, 0, 0, $x, $y, $width, $height);
            imagedestroy($this->image);
            $this->image = $cropped;
        }

        return $this;
    }

    public function rotate(float $degrees): self
    {
        if ($this->image instanceof \Imagick) {
            $this->image->rotateImage(new \ImagickPixel('transparent'), $degrees);
        } else {
            $rotated = imagerotate($this->image, -$degrees, 0);
            if ($rotated !== false) {
                imagedestroy($this->image);
                $this->image = $rotated;
            }
        }

        return $this;
    }

    public function blur(int $amount = 5): self
    {
        if ($this->image instanceof \Imagick) {
            $this->image->blurImage($amount, $amount / 2);
        } else {
            for ($i = 0; $i < $amount; $i++) {
                imagefilter($this->image, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }

        return $this;
    }

    public function grayscale(): self
    {
        if ($this->image instanceof \Imagick) {
            $this->image->modulateImage(100, 0, 100);
        } else {
            imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        }

        return $this;
    }

    public function optimize(int $quality = 85): self
    {
        if ($this->image instanceof \Imagick) {
            $this->image->stripImage();
            $this->image->setImageCompressionQuality($quality);
        }
        // GD optimization happens during encoding

        return $this;
    }

    public function watermark(
        string $watermarkData,
        string $position = 'bottom-right',
        int $opacity = 100,
        int $padding = 10
    ): self {
        $watermark = $this->createFromString($watermarkData);

        if ($this->image instanceof \Imagick) {
            $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);

            [$x, $y] = $this->calculatePosition(
                $this->image->getImageWidth(),
                $this->image->getImageHeight(),
                $watermark->getImageWidth(),
                $watermark->getImageHeight(),
                $position,
                $padding
            );

            $this->image->compositeImage($watermark, \Imagick::COMPOSITE_OVER, $x, $y);
        } else {
            [$x, $y] = $this->calculatePosition(
                imagesx($this->image),
                imagesy($this->image),
                imagesx($watermark),
                imagesy($watermark),
                $position,
                $padding
            );

            imagecopy($this->image, $watermark, $x, $y, 0, 0, imagesx($watermark), imagesy($watermark));
            imagedestroy($watermark);
        }

        return $this;
    }

    public function autoOrient(): self
    {
        if ($this->image instanceof \Imagick) {
            $orientation = $this->image->getImageOrientation();

            switch ($orientation) {
                case \Imagick::ORIENTATION_TOPRIGHT:
                    $this->image->flopImage();
                    break;
                case \Imagick::ORIENTATION_BOTTOMRIGHT:
                    $this->image->rotateImage(new \ImagickPixel(), 180);
                    break;
                case \Imagick::ORIENTATION_BOTTOMLEFT:
                    $this->image->flipImage();
                    break;
                case \Imagick::ORIENTATION_LEFTTOP:
                    $this->image->flopImage();
                    $this->image->rotateImage(new \ImagickPixel(), -90);
                    break;
                case \Imagick::ORIENTATION_RIGHTTOP:
                    $this->image->rotateImage(new \ImagickPixel(), 90);
                    break;
                case \Imagick::ORIENTATION_RIGHTBOTTOM:
                    $this->image->flopImage();
                    $this->image->rotateImage(new \ImagickPixel(), 90);
                    break;
                case \Imagick::ORIENTATION_LEFTBOTTOM:
                    $this->image->rotateImage(new \ImagickPixel(), -90);
                    break;
            }

            $this->image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        }

        return $this;
    }

    public function getDimensions(): array
    {
        if ($this->image instanceof \Imagick) {
            return [
                'width' => $this->image->getImageWidth(),
                'height' => $this->image->getImageHeight(),
            ];
        }

        return [
            'width' => imagesx($this->image),
            'height' => imagesy($this->image),
        ];
    }

    public function encode(string $format = 'jpg', int $quality = 85): string
    {
        if ($this->image instanceof \Imagick) {
            $this->image->setImageFormat($format);
            $this->image->setImageCompressionQuality($quality);
            return $this->image->getImageBlob();
        }

        ob_start();

        match ($format) {
            'png' => imagepng($this->image, null, (int) ((100 - $quality) / 10)),
            'gif' => imagegif($this->image),
            'webp' => imagewebp($this->image, null, $quality),
            default => imagejpeg($this->image, null, $quality),
        };

        return ob_get_clean();
    }

    private function createFromString(string $data): \GdImage|\Imagick
    {
        if ($this->driver === 'imagick') {
            $image = new \Imagick();
            $image->readImageBlob($data);
            return $image;
        }

        $image = imagecreatefromstring($data);
        if ($image === false) {
            throw new ImageProcessingException("Failed to create image from data");
        }

        // Preserve transparency for PNG/GIF
        imagesavealpha($image, true);

        return $image;
    }

    private function resizeGd(int $width, int $height, string $fit): void
    {
        $srcW = imagesx($this->image);
        $srcH = imagesy($this->image);

        if ($fit === 'cover') {
            $ratio = max($width / $srcW, $height / $srcH);
            $newW = (int) ($srcW * $ratio);
            $newH = (int) ($srcH * $ratio);

            $temp = imagecreatetruecolor($newW, $newH);
            imagesavealpha($temp, true);
            imagealphablending($temp, false);
            imagecopyresampled($temp, $this->image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $x = (int) (($newW - $width) / 2);
            $y = (int) (($newH - $height) / 2);

            $result = imagecreatetruecolor($width, $height);
            imagesavealpha($result, true);
            imagealphablending($result, false);
            imagecopy($result, $temp, 0, 0, $x, $y, $width, $height);

            imagedestroy($temp);
            imagedestroy($this->image);
            $this->image = $result;
        } else {
            $ratio = min($width / $srcW, $height / $srcH);
            $newW = (int) ($srcW * $ratio);
            $newH = (int) ($srcH * $ratio);

            $result = imagecreatetruecolor($newW, $newH);
            imagesavealpha($result, true);
            imagealphablending($result, false);
            imagecopyresampled($result, $this->image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            imagedestroy($this->image);
            $this->image = $result;
        }
    }

    private function fitImage(int $maxWidth, int $maxHeight): void
    {
        $width = $this->image->getImageWidth();
        $height = $this->image->getImageHeight();

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return;
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        $this->image->thumbnailImage($newWidth, $newHeight);
    }

    private function calculatePosition(
        int $imageW,
        int $imageH,
        int $overlayW,
        int $overlayH,
        string $position,
        int $padding
    ): array {
        return match ($position) {
            'top-left' => [$padding, $padding],
            'top-right' => [$imageW - $overlayW - $padding, $padding],
            'top-center' => [(int) (($imageW - $overlayW) / 2), $padding],
            'bottom-left' => [$padding, $imageH - $overlayH - $padding],
            'bottom-right' => [$imageW - $overlayW - $padding, $imageH - $overlayH - $padding],
            'bottom-center' => [(int) (($imageW - $overlayW) / 2), $imageH - $overlayH - $padding],
            'center-left' => [$padding, (int) (($imageH - $overlayH) / 2)],
            'center-right' => [$imageW - $overlayW - $padding, (int) (($imageH - $overlayH) / 2)],
            'center' => [(int) (($imageW - $overlayW) / 2), (int) (($imageH - $overlayH) / 2)],
            default => [$imageW - $overlayW - $padding, $imageH - $overlayH - $padding],
        };
    }
}
