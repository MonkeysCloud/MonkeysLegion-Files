<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Files\Image\ImageDriver;
use MonkeysLegion\Files\Image\ImageFormat;
use MonkeysLegion\Files\Visibility;
use MonkeysLegion\Files\Upload\UploadResult;
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Event\FileStored;
use MonkeysLegion\Files\Event\FileDeleted;
use MonkeysLegion\Files\Event\FileMoved;
use MonkeysLegion\Files\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for enums, value objects, and events.
 *
 * @covers \MonkeysLegion\Files\Visibility
 * @covers \MonkeysLegion\Files\Image\ImageDriver
 * @covers \MonkeysLegion\Files\Image\ImageFormat
 * @covers \MonkeysLegion\Files\Upload\UploadResult
 * @covers \MonkeysLegion\Files\Event\FileStored
 * @covers \MonkeysLegion\Files\Event\FileDeleted
 * @covers \MonkeysLegion\Files\Event\FileMoved
 * @covers \MonkeysLegion\Files\Exception\ValidationException
 */
final class CoreTest extends TestCase
{
    // ── Visibility Enum ──────────────────────────────────────────

    public function testVisibilityValues(): void
    {
        $this->assertSame('public', Visibility::Public->value);
        $this->assertSame('private', Visibility::Private->value);
    }

    public function testVisibilityTryFrom(): void
    {
        $this->assertSame(Visibility::Public, Visibility::tryFrom('public'));
        $this->assertNull(Visibility::tryFrom('invalid'));
    }

    // ── ImageDriver Enum ─────────────────────────────────────────

    public function testImageDriverValues(): void
    {
        $this->assertSame('gd', ImageDriver::Gd->value);
        $this->assertSame('imagick', ImageDriver::Imagick->value);
    }

    public function testGdIsAvailable(): void
    {
        // GD should be available in test env
        $this->assertTrue(ImageDriver::Gd->isAvailable());
    }

    // ── ImageFormat Enum ─────────────────────────────────────────

    public function testImageFormatMimeType(): void
    {
        $this->assertSame('image/jpeg', ImageFormat::Jpeg->mimeType());
        $this->assertSame('image/webp', ImageFormat::Webp->mimeType());
        $this->assertSame('image/avif', ImageFormat::Avif->mimeType());
    }

    public function testImageFormatExtension(): void
    {
        $this->assertSame('jpg', ImageFormat::Jpeg->extension());
        $this->assertSame('webp', ImageFormat::Webp->extension());
        $this->assertSame('avif', ImageFormat::Avif->extension());
    }

    // ── UploadResult ─────────────────────────────────────────────

    public function testUploadResultOk(): void
    {
        $file = new FileRecord('local', 'test.jpg', 'test.jpg', 'image/jpeg', 1024);
        $result = UploadResult::ok($file);

        $this->assertTrue($result->success);
        $this->assertFalse($result->failed);
        $this->assertSame($file, $result->file);
        $this->assertEmpty($result->errors);
    }

    public function testUploadResultFail(): void
    {
        $result = UploadResult::fail(['Too large', 'Bad type']);

        $this->assertFalse($result->success);
        $this->assertTrue($result->failed);
        $this->assertNull($result->file);
        $this->assertCount(2, $result->errors);
    }

    // ── Events ───────────────────────────────────────────────────

    public function testFileStoredEvent(): void
    {
        $file = new FileRecord('s3', 'doc.pdf', 'doc.pdf', 'application/pdf', 2048);
        $event = new FileStored(file: $file, disk: 's3');

        $this->assertSame('s3', $event->disk);
        $this->assertSame($file, $event->file);
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    public function testFileDeletedEvent(): void
    {
        $event = new FileDeleted(path: 'old.txt', disk: 'local');

        $this->assertSame('old.txt', $event->path);
        $this->assertSame('local', $event->disk);
    }

    public function testFileMovedEvent(): void
    {
        $event = new FileMoved(
            sourcePath: 'a.txt',
            destinationPath: 'b.txt',
            sourceDisk: 'local',
            destinationDisk: 's3',
        );

        $this->assertSame('a.txt', $event->sourcePath);
        $this->assertSame('b.txt', $event->destinationPath);
    }

    // ── ValidationException ──────────────────────────────────────

    public function testValidationExceptionMessage(): void
    {
        $e = new ValidationException(['Too large', 'Bad type']);
        $this->assertStringContainsString('Too large', $e->getMessage());
        $this->assertCount(2, $e->errors);
    }
}
