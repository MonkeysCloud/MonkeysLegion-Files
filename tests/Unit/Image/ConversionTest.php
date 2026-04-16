<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Image;

use MonkeysLegion\Files\Image\Conversion;
use MonkeysLegion\Files\Image\ConversionRegistry;
use MonkeysLegion\Files\Image\ImageFormat;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Image\Conversion
 * @covers \MonkeysLegion\Files\Image\ConversionRegistry
 */
final class ConversionTest extends TestCase
{
    // ── Conversion VO ────────────────────────────────────────────

    public function testThumbnailFactory(): void
    {
        $c = Conversion::thumbnail('thumb', 200, 200);

        $this->assertSame('thumb', $c->name);
        $this->assertSame(200, $c->width);
        $this->assertSame(200, $c->height);
        $this->assertSame('cover', $c->fit);
    }

    public function testToFormatFactory(): void
    {
        $c = Conversion::toFormat('webp', ImageFormat::Webp, 90);

        $this->assertSame('webp', $c->name);
        $this->assertSame(ImageFormat::Webp, $c->format);
        $this->assertSame(90, $c->quality);
    }

    public function testResizeFactory(): void
    {
        $c = Conversion::resize('large', 1920, 1080);

        $this->assertSame('contain', $c->fit);
    }

    public function testOutputExtensionHook(): void
    {
        $c = Conversion::toFormat('avif', ImageFormat::Avif);
        $this->assertSame('avif', $c->outputExtension);
    }

    public function testOutputMimeTypeHook(): void
    {
        $c = Conversion::toFormat('webp', ImageFormat::Webp);
        $this->assertSame('image/webp', $c->outputMimeType);
    }

    public function testDefaultOutputExtension(): void
    {
        $c = Conversion::thumbnail('thumb', 100, 100);
        $this->assertSame('jpg', $c->outputExtension);
    }

    // ── ConversionRegistry ───────────────────────────────────────

    public function testRegisterAndGet(): void
    {
        $reg = new ConversionRegistry();
        $c   = Conversion::thumbnail('thumb', 200, 200);

        $reg->register($c);

        $this->assertSame(1, $reg->count);
        $this->assertTrue($reg->has('thumb'));
        $this->assertSame($c, $reg->get('thumb'));
    }

    public function testNamesHook(): void
    {
        $reg = new ConversionRegistry();
        $reg->register(Conversion::thumbnail('a', 100, 100));
        $reg->register(Conversion::thumbnail('b', 200, 200));

        $this->assertSame(['a', 'b'], $reg->names);
    }

    public function testRemove(): void
    {
        $reg = new ConversionRegistry();
        $reg->register(Conversion::thumbnail('x', 100, 100));
        $reg->remove('x');

        $this->assertSame(0, $reg->count);
        $this->assertFalse($reg->has('x'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $reg = new ConversionRegistry();
        $this->assertNull($reg->get('nope'));
    }

    public function testAll(): void
    {
        $reg = new ConversionRegistry();
        $reg->register(Conversion::thumbnail('a', 100, 100));
        $reg->register(Conversion::toFormat('b', ImageFormat::Webp));

        $all = $reg->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }
}
