<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Upload;

use MonkeysLegion\Files\Exception\UploadException;
use MonkeysLegion\Files\Upload\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Upload\UploadedFile
 */
final class UploadedFileTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($this->tmpFile, 'test data');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // ── Constructor ──────────────────────────────────────────────

    public function testConstructSetsFields(): void
    {
        $f = new UploadedFile($this->tmpFile, 'photo.jpg', 'image/jpeg', 4096);

        $this->assertSame($this->tmpFile, $f->tmpPath);
        $this->assertSame('photo.jpg', $f->clientName);
        $this->assertSame('image/jpeg', $f->mimeType);
        $this->assertSame(4096, $f->size);
        $this->assertSame(UPLOAD_ERR_OK, $f->error);
    }

    public function testConstructThrowsOnUploadError(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('upload_max_filesize');

        new UploadedFile('/tmp/x', 'big.zip', 'application/zip', 0, UPLOAD_ERR_INI_SIZE);
    }

    public function testConstructThrowsFormSizeError(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('MAX_FILE_SIZE');

        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_FORM_SIZE);
    }

    public function testConstructThrowsPartialError(): void
    {
        $this->expectException(UploadException::class);
        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_PARTIAL);
    }

    public function testConstructThrowsNoFileError(): void
    {
        $this->expectException(UploadException::class);
        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_NO_FILE);
    }

    public function testConstructThrowsNoTmpDirError(): void
    {
        $this->expectException(UploadException::class);
        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_NO_TMP_DIR);
    }

    public function testConstructThrowsCantWriteError(): void
    {
        $this->expectException(UploadException::class);
        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_CANT_WRITE);
    }

    public function testConstructThrowsExtensionError(): void
    {
        $this->expectException(UploadException::class);
        new UploadedFile('/tmp/x', 'f.bin', 'application/octet-stream', 0, UPLOAD_ERR_EXTENSION);
    }

    // ── Computed Hooks ───────────────────────────────────────────

    public function testExtensionHook(): void
    {
        $f = new UploadedFile($this->tmpFile, 'Photo.JPG', 'image/jpeg', 100);
        $this->assertSame('jpg', $f->extension);
    }

    public function testExtensionHookNoExtension(): void
    {
        $f = new UploadedFile($this->tmpFile, 'README', 'text/plain', 100);
        $this->assertSame('', $f->extension);
    }

    public function testIsImageHook(): void
    {
        $img = new UploadedFile($this->tmpFile, 'a.png', 'image/png', 100);
        $this->assertTrue($img->isImage);

        $txt = new UploadedFile($this->tmpFile, 'a.txt', 'text/plain', 100);
        $this->assertFalse($txt->isImage);
    }

    public function testIsVideoHook(): void
    {
        $vid = new UploadedFile($this->tmpFile, 'movie.mp4', 'video/mp4', 100);
        $this->assertTrue($vid->isVideo);

        $img = new UploadedFile($this->tmpFile, 'a.png', 'image/png', 100);
        $this->assertFalse($img->isVideo);
    }

    public function testIsAudioHook(): void
    {
        $aud = new UploadedFile($this->tmpFile, 'song.mp3', 'audio/mpeg', 100);
        $this->assertTrue($aud->isAudio);

        $img = new UploadedFile($this->tmpFile, 'a.png', 'image/png', 100);
        $this->assertFalse($img->isAudio);
    }

    public function testBasenameHook(): void
    {
        $f = new UploadedFile($this->tmpFile, 'report.final.pdf', 'application/pdf', 100);
        $this->assertSame('report.final', $f->basename);
    }

    public function testHumanSizeHookBytes(): void
    {
        $f = new UploadedFile($this->tmpFile, 'a.txt', 'text/plain', 500);
        $this->assertSame('500 B', $f->humanSize);
    }

    public function testHumanSizeHookKb(): void
    {
        $f = new UploadedFile($this->tmpFile, 'a.txt', 'text/plain', 2048);
        $this->assertSame('2 KB', $f->humanSize);
    }

    public function testHumanSizeHookMb(): void
    {
        $f = new UploadedFile($this->tmpFile, 'a.txt', 'text/plain', 5_242_880);
        $this->assertSame('5 MB', $f->humanSize);
    }

    public function testHumanSizeHookGb(): void
    {
        $f = new UploadedFile($this->tmpFile, 'a.txt', 'text/plain', 2_147_483_648);
        $this->assertSame('2 GB', $f->humanSize);
    }

    // ── fromGlobal ───────────────────────────────────────────────

    public function testFromGlobal(): void
    {
        $f = UploadedFile::fromGlobal([
            'tmp_name' => $this->tmpFile,
            'name'     => 'upload.pdf',
            'type'     => 'application/pdf',
            'size'     => 1234,
            'error'    => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('upload.pdf', $f->clientName);
        $this->assertSame('application/pdf', $f->mimeType);
        $this->assertSame(1234, $f->size);
    }

    // ── getStream ────────────────────────────────────────────────

    public function testGetStream(): void
    {
        $f      = new UploadedFile($this->tmpFile, 'data.bin', 'application/octet-stream', 9);
        $stream = $f->getStream();

        $this->assertIsResource($stream);
        $this->assertSame('test data', stream_get_contents($stream));
        fclose($stream);
    }
}
