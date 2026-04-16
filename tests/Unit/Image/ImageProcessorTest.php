<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Image;

use MonkeysLegion\Files\Image\Conversion;
use MonkeysLegion\Files\Image\ConversionRegistry;
use MonkeysLegion\Files\Image\ImageDriver;
use MonkeysLegion\Files\Image\ImageFormat;
use MonkeysLegion\Files\Image\ImageManipulator;
use MonkeysLegion\Files\Image\ImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Image\ImageProcessor
 * @covers \MonkeysLegion\Files\Image\ImageManipulator
 */
final class ImageProcessorTest extends TestCase
{
    private string $testImage;

    protected function setUp(): void
    {
        // Create a 100x80 red PNG
        $img = imagecreatetruecolor(100, 80);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);

        $this->testImage = tempnam(sys_get_temp_dir(), 'ml_img_');
        imagepng($img, $this->testImage);

    }

    protected function tearDown(): void
    {
        if (file_exists($this->testImage)) {
            unlink($this->testImage);
        }
    }

    // ── Property Hooks ───────────────────────────────────────────

    public function testDriverNameHook(): void
    {
        $proc = new ImageProcessor(ImageDriver::Gd);
        $this->assertSame('gd', $proc->driverName);
    }

    public function testIsAvailableHook(): void
    {
        $proc = new ImageProcessor(ImageDriver::Gd);
        $this->assertTrue($proc->isAvailable);
    }

    public function testSupportedFormatsHook(): void
    {
        $proc = new ImageProcessor(ImageDriver::Gd);
        $this->assertNotEmpty($proc->supportedFormats);
    }

    // ── Thumbnail ────────────────────────────────────────────────

    public function testThumbnail(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->thumbnail($this->testImage, 50, 50);

        $this->assertNotEmpty($result);

        $img = imagecreatefromstring($result);
        $this->assertSame(50, imagesx($img));
        $this->assertSame(50, imagesy($img));

    }

    // ── Resize ───────────────────────────────────────────────────

    public function testResizeContain(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->resize($this->testImage, 200, 200);

        $img = imagecreatefromstring($result);
        // contain preserves aspect ratio: 100x80 → scaled to fit 200x200
        // ratio = min(200/100, 200/80) = min(2, 2.5) = 2 → 200x160
        $this->assertSame(200, imagesx($img));
        $this->assertSame(160, imagesy($img));

    }

    // ── Crop ─────────────────────────────────────────────────────

    public function testCrop(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->crop($this->testImage, 30, 30, 10, 10);

        $img = imagecreatefromstring($result);
        $this->assertSame(30, imagesx($img));
        $this->assertSame(30, imagesy($img));

    }

    // ── Convert ──────────────────────────────────────────────────

    public function testConvertToJpeg(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->convert($this->testImage, 'jpg', 90);

        $this->assertNotEmpty($result);

        // Verify it's valid JPEG (starts with FFD8)
        $this->assertSame("\xFF\xD8", substr($result, 0, 2));
    }

    public function testConvertToWebp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support not available');
        }

        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->convert($this->testImage, 'webp', 85);

        $this->assertNotEmpty($result);
        // WebP magic: RIFF
        $this->assertSame('RIFF', substr($result, 0, 4));
    }

    // ── GetDimensions ────────────────────────────────────────────

    public function testGetDimensions(): void
    {
        $proc = new ImageProcessor(ImageDriver::Gd);
        $dims = $proc->getDimensions($this->testImage);

        $this->assertSame(100, $dims['width']);
        $this->assertSame(80, $dims['height']);
    }

    // ── Optimize ─────────────────────────────────────────────────

    public function testOptimize(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->optimize($this->testImage, 75);

        $this->assertNotEmpty($result);
    }

    // ── Filters ──────────────────────────────────────────────────

    public function testGrayscale(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->grayscale($this->testImage);

        $img = imagecreatefromstring($result);
        $this->assertSame(100, imagesx($img));

    }

    public function testBlur(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->blur($this->testImage, 2);

        $this->assertNotEmpty($result);
    }

    public function testRotate(): void
    {
        $proc   = new ImageProcessor(ImageDriver::Gd);
        $result = $proc->rotate($this->testImage, 90);

        $img = imagecreatefromstring($result);
        // After 90° rotation: 100x80 → 80x100
        $this->assertSame(80, imagesx($img));
        $this->assertSame(100, imagesy($img));

    }

    // ── Named Conversions ────────────────────────────────────────

    public function testApplyConversion(): void
    {
        $proc       = new ImageProcessor(ImageDriver::Gd);
        $conversion = Conversion::thumbnail('thumb', 40, 40);

        $result = $proc->applyConversion($this->testImage, $conversion);

        $img = imagecreatefromstring($result);
        $this->assertSame(40, imagesx($img));

    }

    public function testProcessConversions(): void
    {
        $registry = new ConversionRegistry();
        $registry->register(Conversion::thumbnail('small', 20, 20));
        $registry->register(Conversion::thumbnail('medium', 50, 50));

        $proc    = new ImageProcessor(ImageDriver::Gd, registry: $registry);
        $results = $proc->processConversions($this->testImage);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('small', $results);
        $this->assertArrayHasKey('medium', $results);
    }

    public function testProcessConversionsSubset(): void
    {
        $registry = new ConversionRegistry();
        $registry->register(Conversion::thumbnail('a', 20, 20));
        $registry->register(Conversion::thumbnail('b', 50, 50));
        $registry->register(Conversion::thumbnail('c', 80, 80));

        $proc    = new ImageProcessor(ImageDriver::Gd, registry: $registry);
        $results = $proc->processConversions($this->testImage, ['b']);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('b', $results);
    }

    // ── ImageManipulator Hooks ───────────────────────────────────

    public function testManipulatorPropertyHooks(): void
    {
        $data = file_get_contents($this->testImage);
        $m    = new ImageManipulator($data, ImageDriver::Gd);

        $this->assertSame(100, $m->width);
        $this->assertSame(80, $m->height);
        $this->assertFalse($m->isImagick);
    }
}
