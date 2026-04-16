<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

use MonkeysLegion\Files\Exception\ImageProcessingException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Low-level image manipulation wrapping GD and Imagick.
 * Not meant to be used directly — use ImageProcessor instead.
 *
 * @internal
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ImageManipulator
{
    private \GdImage|\Imagick $image;

    /** Current image width. */
    public int $width {
        get => $this->image instanceof \Imagick
            ? $this->image->getImageWidth()
            : imagesx($this->image);
    }

    /** Current image height. */
    public int $height {
        get => $this->image instanceof \Imagick
            ? $this->image->getImageHeight()
            : imagesy($this->image);
    }

    /** Whether the image uses the Imagick driver. */
    public bool $isImagick {
        get => $this->image instanceof \Imagick;
    }

    public function __construct(string $data, private readonly ImageDriver $driver = ImageDriver::Gd)
    {
        $this->image = $this->createFromString($data);
    }

    public function __destruct()
    {
        if ($this->image instanceof \Imagick) {
            $this->image->destroy();
        }
    }

    // ── Operations ───────────────────────────────────────────────

    public function resize(int $width, int $height, string $fit = 'cover'): self
    {
        if ($this->isImagick) {
            match ($fit) {
                'contain' => $this->image->thumbnailImage($width, $height, true),
                'cover'   => $this->image->cropThumbnailImage($width, $height),
                'stretch' => $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1),
                'fit'     => $this->fitImagick($width, $height),
                default   => $this->image->cropThumbnailImage($width, $height),
            };
        } else {
            $this->resizeGd($width, $height, $fit);
        }

        return $this;
    }

    public function crop(int $width, int $height, int $x = 0, int $y = 0): self
    {
        if ($this->isImagick) {
            $this->image->cropImage($width, $height, $x, $y);
        } else {
            $cropped = imagecreatetruecolor($width, $height);
            $this->preserveTransparency($cropped);
            imagecopy($cropped, $this->image, 0, 0, $x, $y, $width, $height);
            $this->image = $cropped;
        }

        return $this;
    }

    public function rotate(float $degrees): self
    {
        if ($this->isImagick) {
            $this->image->rotateImage(new \ImagickPixel('transparent'), $degrees);
        } else {
            $rotated = imagerotate($this->image, -$degrees, 0);

            if ($rotated !== false) {
                $this->image = $rotated;
            }
        }

        return $this;
    }

    public function blur(int $amount = 5): self
    {
        if ($this->isImagick) {
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
        if ($this->isImagick) {
            $this->image->modulateImage(100, 0, 100);
        } else {
            imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        }

        return $this;
    }

    public function stripMetadata(): self
    {
        if ($this->isImagick) {
            $this->image->stripImage();
        }
        // GD strips metadata during encoding

        return $this;
    }

    public function watermark(
        string $watermarkData,
        string $position = 'bottom-right',
        int $opacity = 100,
        int $padding = 10,
    ): self {
        $wm = $this->createFromString($watermarkData);

        if ($this->isImagick) {
            /** @var \Imagick $wm */
            $wm->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);

            [$x, $y] = $this->calculatePosition(
                $this->width, $this->height,
                $wm->getImageWidth(), $wm->getImageHeight(),
                $position, $padding,
            );

            $this->image->compositeImage($wm, \Imagick::COMPOSITE_OVER, $x, $y);
            $wm->destroy();
        } else {
            /** @var \GdImage $wm */
            [$x, $y] = $this->calculatePosition(
                $this->width, $this->height,
                imagesx($wm), imagesy($wm),
                $position, $padding,
            );

            imagecopy($this->image, $wm, $x, $y, 0, 0, imagesx($wm), imagesy($wm));
        }

        return $this;
    }

    public function autoOrient(): self
    {
        if (!$this->isImagick) {
            return $this;
        }

        $orientation = $this->image->getImageOrientation();

        match ($orientation) {
            \Imagick::ORIENTATION_TOPRIGHT    => $this->image->flopImage(),
            \Imagick::ORIENTATION_BOTTOMRIGHT => $this->image->rotateImage(new \ImagickPixel(), 180),
            \Imagick::ORIENTATION_BOTTOMLEFT  => $this->image->flipImage(),
            \Imagick::ORIENTATION_LEFTTOP     => (function () {
                $this->image->flopImage();
                $this->image->rotateImage(new \ImagickPixel(), -90);
            })(),
            \Imagick::ORIENTATION_RIGHTTOP     => $this->image->rotateImage(new \ImagickPixel(), 90),
            \Imagick::ORIENTATION_RIGHTBOTTOM  => (function () {
                $this->image->flopImage();
                $this->image->rotateImage(new \ImagickPixel(), 90);
            })(),
            \Imagick::ORIENTATION_LEFTBOTTOM   => $this->image->rotateImage(new \ImagickPixel(), -90),
            default => null,
        };

        $this->image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

        return $this;
    }

    /**
     * @return array{width: int, height: int}
     */
    public function getDimensions(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }

    // ── Encoding ─────────────────────────────────────────────────

    /**
     * Encode the image to a binary string.
     *
     * @param string|null $format  Output format (jpg, png, webp, avif, gif) or null for JPEG
     * @param int         $quality Output quality (0–100)
     */
    public function encode(?string $format = null, int $quality = 85): string
    {
        $format ??= 'jpg';

        if ($this->isImagick) {
            $this->image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
            $this->image->setImageCompressionQuality($quality);

            return $this->image->getImageBlob();
        }

        ob_start();

        match ($format) {
            'png'  => imagepng($this->image, null, (int) ((100 - $quality) / 10)),
            'gif'  => imagegif($this->image),
            'webp' => imagewebp($this->image, null, $quality),
            'avif' => imageavif($this->image, null, $quality),
            default => imagejpeg($this->image, null, $quality),
        };

        $result = ob_get_clean();

        return $result !== false ? $result : throw new ImageProcessingException('Encoding failed');
    }

    // ── Internal ─────────────────────────────────────────────────

    private function createFromString(string $data): \GdImage|\Imagick
    {
        if ($this->driver === ImageDriver::Imagick) {
            $image = new \Imagick();
            $image->readImageBlob($data);

            return $image;
        }

        $image = @imagecreatefromstring($data);

        if ($image === false) {
            throw new ImageProcessingException('Failed to create image from data');
        }

        $this->preserveTransparency($image);

        return $image;
    }

    private function preserveTransparency(\GdImage $image): void
    {
        imagesavealpha($image, true);
        imagealphablending($image, false);
    }

    private function resizeGd(int $width, int $height, string $fit): void
    {
        $srcW = $this->width;
        $srcH = $this->height;

        if ($fit === 'cover') {
            $ratio = max($width / $srcW, $height / $srcH);
            $newW  = (int) ($srcW * $ratio);
            $newH  = (int) ($srcH * $ratio);

            $temp = imagecreatetruecolor($newW, $newH);
            $this->preserveTransparency($temp);
            imagecopyresampled($temp, $this->image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $x = (int) (($newW - $width) / 2);
            $y = (int) (($newH - $height) / 2);

            $result = imagecreatetruecolor($width, $height);
            $this->preserveTransparency($result);
            imagecopy($result, $temp, 0, 0, $x, $y, $width, $height);

            $this->image = $result;
        } else {
            // contain / stretch
            if ($fit === 'stretch') {
                $newW = $width;
                $newH = $height;
            } else {
                $ratio = min($width / $srcW, $height / $srcH);
                $newW  = (int) ($srcW * $ratio);
                $newH  = (int) ($srcH * $ratio);
            }

            $result = imagecreatetruecolor($newW, $newH);
            $this->preserveTransparency($result);
            imagecopyresampled($result, $this->image, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

            $this->image = $result;
        }
    }

    private function fitImagick(int $maxWidth, int $maxHeight): void
    {
        $w = $this->image->getImageWidth();
        $h = $this->image->getImageHeight();

        if ($w <= $maxWidth && $h <= $maxHeight) {
            return;
        }

        $ratio = min($maxWidth / $w, $maxHeight / $h);

        $this->image->thumbnailImage((int) ($w * $ratio), (int) ($h * $ratio));
    }

    /**
     * @return array{int, int} [x, y]
     */
    private function calculatePosition(
        int $imageW, int $imageH,
        int $overlayW, int $overlayH,
        string $position,
        int $padding,
    ): array {
        return match ($position) {
            'top-left'      => [$padding, $padding],
            'top-right'     => [$imageW - $overlayW - $padding, $padding],
            'top-center'    => [(int) (($imageW - $overlayW) / 2), $padding],
            'bottom-left'   => [$padding, $imageH - $overlayH - $padding],
            'bottom-right'  => [$imageW - $overlayW - $padding, $imageH - $overlayH - $padding],
            'bottom-center' => [(int) (($imageW - $overlayW) / 2), $imageH - $overlayH - $padding],
            'center-left'   => [$padding, (int) (($imageH - $overlayH) / 2)],
            'center-right'  => [$imageW - $overlayW - $padding, (int) (($imageH - $overlayH) / 2)],
            'center'        => [(int) (($imageW - $overlayW) / 2), (int) (($imageH - $overlayH) / 2)],
            default         => [$imageW - $overlayW - $padding, $imageH - $overlayH - $padding],
        };
    }
}
