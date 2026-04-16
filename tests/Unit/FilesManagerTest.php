<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\FilesManager;
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Upload\UploadValidator;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\FilesManager
 */
final class FilesManagerTest extends TestCase
{
    private FilesManager $manager;
    private MemoryDriver $local;
    private MemoryDriver $backup;

    protected function setUp(): void
    {
        $this->local  = new MemoryDriver();
        $this->backup = new MemoryDriver();

        $this->manager = new FilesManager(
            disks: ['local' => $this->local, 'backup' => $this->backup],
            defaultDisk: 'local',
        );
    }

    // ── Disk Access ──────────────────────────────────────────────

    public function testDiskReturnsDefault(): void
    {
        $this->assertSame($this->local, $this->manager->disk());
    }

    public function testDiskByName(): void
    {
        $this->assertSame($this->backup, $this->manager->disk('backup'));
    }

    public function testDiskThrowsForUnknown(): void
    {
        $this->expectException(StorageException::class);
        $this->manager->disk('nonexistent');
    }

    public function testDiskCountHook(): void
    {
        $this->assertSame(2, $this->manager->diskCount);
    }

    public function testAddDisk(): void
    {
        $this->manager->addDisk('archive', new MemoryDriver());
        $this->assertSame(3, $this->manager->diskCount);
    }

    // ── File Operations ──────────────────────────────────────────

    public function testPutAndGet(): void
    {
        $this->assertTrue($this->manager->put('test.txt', 'hello'));
        $this->assertSame('hello', $this->manager->get('test.txt'));
    }

    public function testPutOnSpecificDisk(): void
    {
        $this->manager->put('backup.txt', 'data', 'backup');
        $this->assertSame('data', $this->manager->get('backup.txt', 'backup'));
        $this->assertNull($this->manager->get('backup.txt', 'local'));
    }

    public function testPutStream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'streamed');
        rewind($stream);

        $this->assertTrue($this->manager->putStream('s.txt', $stream));
        fclose($stream);

        $this->assertSame('streamed', $this->manager->get('s.txt'));
    }

    public function testGetStream(): void
    {
        $this->manager->put('data.txt', 'contents');
        $stream = $this->manager->getStream('data.txt');
        $this->assertIsResource($stream);
        fclose($stream);
    }

    public function testDelete(): void
    {
        $this->manager->put('del.txt', 'bye');
        $this->assertTrue($this->manager->delete('del.txt'));
        $this->assertFalse($this->manager->exists('del.txt'));
    }

    public function testExists(): void
    {
        $this->assertFalse($this->manager->exists('nope.txt'));
        $this->manager->put('exists.txt', 'x');
        $this->assertTrue($this->manager->exists('exists.txt'));
    }

    public function testSize(): void
    {
        $this->manager->put('size.txt', 'abcde');
        $this->assertSame(5, $this->manager->size('size.txt'));
    }

    public function testChecksum(): void
    {
        $this->manager->put('hash.txt', 'data');
        $this->assertSame(hash('sha256', 'data'), $this->manager->checksum('hash.txt'));
    }

    public function testUrl(): void
    {
        $url = $this->manager->url('path/file.txt');
        $this->assertStringContainsString('path/file.txt', $url);
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function testCopy(): void
    {
        $this->manager->put('a.txt', 'alpha');
        $this->assertTrue($this->manager->copy('a.txt', 'b.txt'));
        $this->assertSame('alpha', $this->manager->get('b.txt'));
    }

    public function testMove(): void
    {
        $this->manager->put('src.txt', 'beta');
        $this->assertTrue($this->manager->move('src.txt', 'dst.txt'));
        $this->assertNull($this->manager->get('src.txt'));
        $this->assertSame('beta', $this->manager->get('dst.txt'));
    }

    // ── Cross-Disk ───────────────────────────────────────────────

    public function testCrossDiskCopy(): void
    {
        $this->manager->put('file.txt', 'cross-copy', 'local');

        $this->assertTrue($this->manager->crossDiskCopy(
            'file.txt', 'file.txt', 'local', 'backup',
        ));

        $this->assertSame('cross-copy', $this->manager->get('file.txt', 'backup'));
        // Original still exists
        $this->assertSame('cross-copy', $this->manager->get('file.txt', 'local'));
    }

    public function testCrossDiskMove(): void
    {
        $this->manager->put('migrate.txt', 'moving', 'local');

        $this->assertTrue($this->manager->crossDiskMove(
            'migrate.txt', 'migrate.txt', 'local', 'backup',
        ));

        $this->assertSame('moving', $this->manager->get('migrate.txt', 'backup'));
        $this->assertNull($this->manager->get('migrate.txt', 'local'));
    }

    public function testCrossDiskCopyThrowsForMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->manager->crossDiskCopy('nope.txt', 'nope.txt', 'local', 'backup');
    }

    // ── Upload ───────────────────────────────────────────────────

    public function testUploadSuccess(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        file_put_contents($tmp, 'file contents');

        $file = new UploadedFile(
            tmpPath: $tmp,
            clientName: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 13,
        );

        $result = $this->manager->upload($file, 'uploads');

        $this->assertTrue($result->success);
        $this->assertFalse($result->failed);
        $this->assertNotNull($result->file);
        $this->assertSame('photo.jpg', $result->file->originalName);

        unlink($tmp);
    }

    public function testUploadWithValidationFailure(): void
    {
        $manager = new FilesManager(
            disks: ['local' => $this->local],
            validator: new UploadValidator(maxSize: 5),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'ml_test_');
        file_put_contents($tmp, str_repeat('x', 100));

        $file = new UploadedFile(
            tmpPath: $tmp,
            clientName: 'big.txt',
            mimeType: 'text/plain',
            size: 100,
        );

        $result = $manager->upload($file, 'uploads');

        $this->assertTrue($result->failed);
        $this->assertNotEmpty($result->errors);

        unlink($tmp);
    }

    // ── Listing ──────────────────────────────────────────────────

    public function testFilesListing(): void
    {
        $this->manager->put('dir/a.txt', 'x');
        $this->manager->put('dir/b.txt', 'y');

        $files = $this->manager->files('dir');
        $this->assertCount(2, $files);
    }

    public function testDirectoriesListing(): void
    {
        $this->manager->put('root/sub/a.txt', 'x');

        $dirs = $this->manager->directories('root');
        $this->assertCount(1, $dirs);
    }
}
