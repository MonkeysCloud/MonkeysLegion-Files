<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Upload;

use MonkeysLegion\Files\Exception\ValidationException;
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Upload\UploadValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Upload\UploadValidator
 * @covers \MonkeysLegion\Files\Upload\UploadedFile
 */
final class UploadValidatorTest extends TestCase
{
    private function makeFile(
        string $name = 'photo.jpg',
        string $mime = 'image/jpeg',
        int $size = 1024,
    ): UploadedFile {
        // Create a real temp file so fopen works
        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        file_put_contents($tmp, str_repeat('x', $size));

        return new UploadedFile(
            tmpPath: $tmp,
            clientName: $name,
            mimeType: $mime,
            size: $size,
        );
    }

    // ── UploadedFile hooks ───────────────────────────────────────

    public function testExtensionHook(): void
    {
        $file = $this->makeFile('report.PDF');
        $this->assertSame('pdf', $file->extension);
    }

    public function testIsImageHook(): void
    {
        $this->assertTrue($this->makeFile('a.jpg', 'image/jpeg')->isImage);
        $this->assertFalse($this->makeFile('a.pdf', 'application/pdf')->isImage);
    }

    public function testHumanSizeHook(): void
    {
        $this->assertSame('1 KB', $this->makeFile(size: 1024)->humanSize);
    }

    public function testGetStream(): void
    {
        $file = $this->makeFile();
        $stream = $file->getStream();
        $this->assertIsResource($stream);
        fclose($stream);
    }

    // ── Validator: passes ────────────────────────────────────────

    public function testPassesWithDefaults(): void
    {
        $v = new UploadValidator();
        $this->assertTrue($v->passes($this->makeFile()));
    }

    public function testPassesWithAllowedMime(): void
    {
        $v = new UploadValidator(allowedMimes: ['image/jpeg', 'image/png']);
        $this->assertTrue($v->passes($this->makeFile('a.jpg', 'image/jpeg')));
    }

    // ── Validator: size ──────────────────────────────────────────

    public function testFailsOnSizeExceeded(): void
    {
        $v = new UploadValidator(maxSize: 100);

        $this->expectException(ValidationException::class);
        $v->validate($this->makeFile(size: 500));
    }

    // ── Validator: MIME ──────────────────────────────────────────

    public function testFailsOnDisallowedMime(): void
    {
        $v = new UploadValidator(allowedMimes: ['image/png']);

        $this->expectException(ValidationException::class);
        $v->validate($this->makeFile('a.jpg', 'image/jpeg'));
    }

    // ── Validator: extension ─────────────────────────────────────

    public function testFailsOnDeniedExtension(): void
    {
        $v = new UploadValidator();

        $this->expectException(ValidationException::class);
        $v->validate($this->makeFile('evil.php', 'application/x-php'));
    }

    public function testPassesNonDeniedExtension(): void
    {
        $v = new UploadValidator();
        $this->assertTrue($v->passes($this->makeFile('doc.txt', 'text/plain')));
    }

    // ── ValidationException ──────────────────────────────────────

    public function testValidationExceptionCarriesErrors(): void
    {
        $v = new UploadValidator(maxSize: 10, allowedMimes: ['image/png']);

        try {
            $v->validate($this->makeFile('a.jpg', 'image/jpeg', 500));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->errors);
        }
    }
}
