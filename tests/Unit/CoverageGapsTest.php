<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\FilesException;
use MonkeysLegion\Files\Exception\ImageProcessingException;
use MonkeysLegion\Files\Exception\SecurityException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Exception\UploadException;
use MonkeysLegion\Files\Exception\ValidationException;
use MonkeysLegion\Files\Image\ImageDriver;
use MonkeysLegion\Files\Image\ImageFormat;
use MonkeysLegion\Files\Upload\UploadResult;
use MonkeysLegion\Files\Upload\UploadValidator;
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * Tests for remaining coverage gaps: enums, exceptions, edge cases.
 *
 * @covers \MonkeysLegion\Files\Exception\FilesException
 * @covers \MonkeysLegion\Files\Exception\StorageException
 * @covers \MonkeysLegion\Files\Exception\UploadException
 * @covers \MonkeysLegion\Files\Exception\SecurityException
 * @covers \MonkeysLegion\Files\Exception\ImageProcessingException
 * @covers \MonkeysLegion\Files\Image\ImageFormat
 * @covers \MonkeysLegion\Files\Image\ImageDriver
 * @covers \MonkeysLegion\Files\Upload\UploadValidator
 */
final class CoverageGapsTest extends TestCase
{
    // ── Exception hierarchy ──────────────────────────────────────

    public function testFilesExceptionIsRuntime(): void
    {
        $e = new FilesException('base error');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('base error', $e->getMessage());
    }

    public function testStorageExceptionExtends(): void
    {
        $e = new StorageException('disk full');
        $this->assertInstanceOf(FilesException::class, $e);
    }

    public function testUploadExceptionExtends(): void
    {
        $e = new UploadException('upload failed');
        $this->assertInstanceOf(FilesException::class, $e);
    }

    public function testSecurityExceptionExtends(): void
    {
        $e = new SecurityException('access denied');
        $this->assertInstanceOf(FilesException::class, $e);
    }

    public function testImageProcessingExceptionExtends(): void
    {
        $e = new ImageProcessingException('resize failed');
        $this->assertInstanceOf(FilesException::class, $e);
    }

    public function testFileNotFoundExceptionHierarchy(): void
    {
        $prev = new \RuntimeException('cause');
        $e    = new FileNotFoundException('missing.txt', $prev);

        $this->assertInstanceOf(FilesException::class, $e);
        $this->assertSame('missing.txt', $e->filePath);
        $this->assertSame($prev, $e->getPrevious());
    }

    // ── ImageFormat full coverage ────────────────────────────────

    public function testImageFormatPng(): void
    {
        $this->assertSame('image/png', ImageFormat::Png->mimeType());
        $this->assertSame('png', ImageFormat::Png->extension());
    }

    public function testImageFormatGif(): void
    {
        $this->assertSame('image/gif', ImageFormat::Gif->mimeType());
        $this->assertSame('gif', ImageFormat::Gif->extension());
    }

    public function testImageFormatJpeg(): void
    {
        $this->assertSame('image/jpeg', ImageFormat::Jpeg->mimeType());
        $this->assertSame('jpg', ImageFormat::Jpeg->extension());
    }

    public function testImageFormatWebp(): void
    {
        $this->assertSame('image/webp', ImageFormat::Webp->mimeType());
        $this->assertSame('webp', ImageFormat::Webp->extension());
    }

    public function testImageFormatAvif(): void
    {
        $this->assertSame('image/avif', ImageFormat::Avif->mimeType());
        $this->assertSame('avif', ImageFormat::Avif->extension());
    }

    // ── ImageDriver ──────────────────────────────────────────────

    public function testImageDriverImagickUnavailable(): void
    {
        // ext-imagick is not installed in test environment
        $this->assertFalse(ImageDriver::Imagick->isAvailable());
    }

    // ── Visibility ───────────────────────────────────────────────

    public function testVisibilityFrom(): void
    {
        $this->assertSame(Visibility::Public, Visibility::from('public'));
        $this->assertSame(Visibility::Private, Visibility::from('private'));
    }

    // ── UploadValidator hooks ────────────────────────────────────

    public function testUploadValidatorRuleCount(): void
    {
        $v = new UploadValidator(maxSize: 1024, allowedMimes: ['image/jpeg']);
        $this->assertSame(3, $v->ruleCount); // maxSize + allowedMimes + deniedExtensions
    }

    public function testUploadValidatorRuleCountNoSize(): void
    {
        $v = new UploadValidator();
        $this->assertSame(1, $v->ruleCount); // only deniedExtensions
    }

    public function testUploadValidatorRuleCountEmpty(): void
    {
        $v = new UploadValidator(deniedExtensions: []);
        $this->assertSame(0, $v->ruleCount);
    }

    public function testUploadValidatorHumanMaxSize(): void
    {
        $v = new UploadValidator(maxSize: 5_242_880);
        $this->assertSame('5 MB', $v->humanMaxSize);
    }

    public function testUploadValidatorHumanMaxSizeUnlimited(): void
    {
        $v = new UploadValidator();
        $this->assertSame('unlimited', $v->humanMaxSize);
    }

    public function testUploadValidatorPassesMethod(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, 'ok');

        $v = new UploadValidator(maxSize: 1024);
        $f = new UploadedFile($tmp, 'readme.txt', 'text/plain', 2);

        $this->assertTrue($v->passes($f));

        unlink($tmp);
    }

    public function testUploadValidatorPassesFails(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, str_repeat('x', 100));

        $v = new UploadValidator(maxSize: 5);
        $f = new UploadedFile($tmp, 'big.txt', 'text/plain', 100);

        $this->assertFalse($v->passes($f));

        unlink($tmp);
    }

    public function testUploadValidatorDeniedExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, '<?php echo "evil";');

        $v = new UploadValidator();
        $f = new UploadedFile($tmp, 'evil.php', 'text/plain', 18);

        $this->assertFalse($v->passes($f));

        unlink($tmp);
    }

    public function testUploadValidatorAllowedMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, 'x');

        $v = new UploadValidator(allowedMimes: ['image/jpeg']);
        $f = new UploadedFile($tmp, 'readme.txt', 'text/plain', 1);

        $this->assertFalse($v->passes($f));

        unlink($tmp);
    }

    // ── UploadResult ─────────────────────────────────────────────

    public function testUploadResultFailWithNoErrors(): void
    {
        $result = UploadResult::fail([]);
        $this->assertTrue($result->failed);
        $this->assertEmpty($result->errors);
    }

    // ── ValidationException hooks ────────────────────────────────

    public function testValidationExceptionEmptyErrors(): void
    {
        $e = new ValidationException([]);
        $this->assertSame(0, $e->errorCount);
        $this->assertSame('', $e->firstError);
    }
}
