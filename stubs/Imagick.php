<?php
/**
 * Imagick stubs for IDE autocompletion and static analysis.
 * Only loaded when ext-imagick is NOT installed.
 *
 * @phpstan-ignore
 */

if (!class_exists(\Imagick::class)) {
    class Imagick
    {
        public const FILTER_LANCZOS = 13;
        public const COMPOSITE_OVER = 40;
        public const CHANNEL_ALPHA = 8;
        public const EVALUATE_MULTIPLY = 4;
        public const ORIENTATION_TOPLEFT = 1;
        public const ORIENTATION_TOPRIGHT = 2;
        public const ORIENTATION_BOTTOMRIGHT = 3;
        public const ORIENTATION_BOTTOMLEFT = 4;
        public const ORIENTATION_LEFTTOP = 5;
        public const ORIENTATION_RIGHTTOP = 6;
        public const ORIENTATION_RIGHTBOTTOM = 7;
        public const ORIENTATION_LEFTBOTTOM = 8;

        public function readImageBlob(string $image): bool { return true; }
        public function getImageWidth(): int { return 0; }
        public function getImageHeight(): int { return 0; }
        public function thumbnailImage(int $columns, int $rows, bool $bestfit = false): bool { return true; }
        public function cropThumbnailImage(int $width, int $height): bool { return true; }
        public function resizeImage(int $columns, int $rows, int $filter, float $blur): bool { return true; }
        public function cropImage(int $width, int $height, int $x, int $y): bool { return true; }
        public function rotateImage(ImagickPixel $background, float $degrees): bool { return true; }
        public function flopImage(): bool { return true; }
        public function flipImage(): bool { return true; }
        public function blurImage(float $radius, float $sigma, int $channel = 0): bool { return true; }
        public function modulateImage(float $brightness, float $saturation, float $hue): bool { return true; }
        public function stripImage(): bool { return true; }
        public function setImageFormat(string $format): bool { return true; }
        public function setImageCompressionQuality(int $quality): bool { return true; }
        public function getImageBlob(): string { return ''; }
        public function getImageOrientation(): int { return 0; }
        public function setImageOrientation(int $orientation): bool { return true; }
        public function compositeImage(Imagick $composite, int $composite_constant, int $x, int $y): bool { return true; }
        public function evaluateImage(int $op, float $constant, int $channel = 0): bool { return true; }
        public function destroy(): bool { return true; }
    }
}

if (!class_exists(\ImagickPixel::class)) {
    class ImagickPixel
    {
        public function __construct(string $color = '') {}
    }
}
