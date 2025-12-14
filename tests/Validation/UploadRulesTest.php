<?php

namespace MonkeysLegion\Files\Tests\Validation;

use MonkeysLegion\Files\Exceptions\UploadException;
use MonkeysLegion\Files\Validation\UploadRules;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class UploadRulesTest extends TestCase
{
    public function testSuccess(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(100);
        $file->method('getClientMediaType')->willReturn('image/png');

        UploadRules::validate($file, 200, ['image/png']);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testUploadError(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Upload failed with code ' . UPLOAD_ERR_INI_SIZE);

        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_INI_SIZE);

        UploadRules::validate($file, 200, []);
    }

    public function testSizeExceeded(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('File too large.');

        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(300);

        UploadRules::validate($file, 200, []);
    }

    public function testDisallowedMime(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Disallowed MIME type.');

        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(100);
        $file->method('getClientMediaType')->willReturn('application/exe');

        UploadRules::validate($file, 200, ['image/png']);
    }

    public function testEmptyMimeAllowed(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(100);
        $file->method('getClientMediaType')->willReturn('application/anything');

        UploadRules::validate($file, 200, []); // empty array allows all? No code says: `if ($mimeAllow && ...)` which means if mimeAllow is empty, condition `!in_array` is skipped.
        
        $this->assertTrue(true);
    }
}
