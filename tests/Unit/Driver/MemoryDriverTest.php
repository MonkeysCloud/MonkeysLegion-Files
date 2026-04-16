<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Driver;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Driver\MemoryDriver
 */
final class MemoryDriverTest extends TestCase
{
    private MemoryDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new MemoryDriver();
    }

    // ── Put / Get ────────────────────────────────────────────────

    public function testPutAndGet(): void
    {
        $this->assertTrue($this->driver->put('test.txt', 'hello'));
        $this->assertSame('hello', $this->driver->get('test.txt'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->get('missing.txt'));
    }

    public function testPutStream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'stream data');
        rewind($stream);

        $this->assertTrue($this->driver->putStream('stream.txt', $stream));
        fclose($stream);

        $this->assertSame('stream data', $this->driver->get('stream.txt'));
    }

    public function testPutStreamFailsWithNonResource(): void
    {
        $this->assertFalse($this->driver->putStream('fail.txt', 'not-a-stream'));
    }

    public function testAppend(): void
    {
        $this->driver->put('log.txt', 'line1');
        $this->driver->append('log.txt', "\nline2");

        $this->assertSame("line1\nline2", $this->driver->get('log.txt'));
    }

    public function testPrepend(): void
    {
        $this->driver->put('log.txt', 'line2');
        $this->driver->prepend('log.txt', "line1\n");

        $this->assertSame("line1\nline2", $this->driver->get('log.txt'));
    }

    // ── GetStream ────────────────────────────────────────────────

    public function testGetStream(): void
    {
        $this->driver->put('data.txt', 'contents');
        $stream = $this->driver->getStream('data.txt');

        $this->assertIsResource($stream);
        $this->assertSame('contents', stream_get_contents($stream));
        fclose($stream);
    }

    public function testGetStreamReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->getStream('nope.txt'));
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDelete(): void
    {
        $this->driver->put('del.txt', 'bye');
        $this->assertTrue($this->driver->delete('del.txt'));
        $this->assertNull($this->driver->get('del.txt'));
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertTrue($this->driver->delete('nonexistent.txt'));
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function testExists(): void
    {
        $this->assertFalse($this->driver->exists('f.txt'));
        $this->driver->put('f.txt', 'x');
        $this->assertTrue($this->driver->exists('f.txt'));
    }

    public function testSize(): void
    {
        $this->driver->put('size.txt', 'abcde');
        $this->assertSame(5, $this->driver->size('size.txt'));
    }

    public function testSizeReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->size('nope.txt'));
    }

    public function testMimeType(): void
    {
        // Plain text
        $this->driver->put('plain.txt', 'Hello world');
        $mime = $this->driver->mimeType('plain.txt');
        $this->assertSame('text/plain', $mime);
    }

    public function testMimeTypeReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->mimeType('nope.txt'));
    }

    public function testLastModified(): void
    {
        $this->driver->put('ts.txt', 'x');
        $ts = $this->driver->lastModified('ts.txt');
        $this->assertIsInt($ts);
        $this->assertGreaterThan(0, $ts);
    }

    public function testChecksum(): void
    {
        $this->driver->put('hash.txt', 'data');
        $this->assertSame(hash('sha256', 'data'), $this->driver->checksum('hash.txt'));
        $this->assertSame(hash('md5', 'data'), $this->driver->checksum('hash.txt', 'md5'));
    }

    // ── Visibility ───────────────────────────────────────────────

    public function testDefaultVisibility(): void
    {
        $this->driver->put('priv.txt', 'x');
        $this->assertSame(Visibility::Private, $this->driver->visibility('priv.txt'));
    }

    public function testSetVisibility(): void
    {
        $this->driver->put('vis.txt', 'x');
        $this->driver->setVisibility('vis.txt', Visibility::Public);
        $this->assertSame(Visibility::Public, $this->driver->visibility('vis.txt'));
    }

    public function testSetVisibilityThrowsForMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->driver->setVisibility('nope.txt', Visibility::Public);
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function testCopy(): void
    {
        $this->driver->put('a.txt', 'alpha');
        $this->assertTrue($this->driver->copy('a.txt', 'b.txt'));

        $this->assertSame('alpha', $this->driver->get('a.txt'));
        $this->assertSame('alpha', $this->driver->get('b.txt'));
    }

    public function testCopyThrowsForMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->driver->copy('missing.txt', 'dest.txt');
    }

    public function testMove(): void
    {
        $this->driver->put('src.txt', 'beta');
        $this->assertTrue($this->driver->move('src.txt', 'dst.txt'));

        $this->assertNull($this->driver->get('src.txt'));
        $this->assertSame('beta', $this->driver->get('dst.txt'));
    }

    // ── Directory Operations ─────────────────────────────────────

    public function testFilesListing(): void
    {
        $this->driver->put('dir/file1.txt', 'a');
        $this->driver->put('dir/file2.txt', 'b');
        $this->driver->put('dir/sub/file3.txt', 'c');

        $files = $this->driver->files('dir');
        $this->assertCount(2, $files);

        $recursive = $this->driver->files('dir', true);
        $this->assertCount(3, $recursive);
    }

    public function testDirectoriesListing(): void
    {
        $this->driver->put('root/sub1/a.txt', 'x');
        $this->driver->put('root/sub2/b.txt', 'y');

        $dirs = $this->driver->directories('root');
        $this->assertCount(2, $dirs);
    }

    public function testDeleteDirectory(): void
    {
        $this->driver->put('d/a.txt', '1');
        $this->driver->put('d/b.txt', '2');

        $this->assertTrue($this->driver->deleteDirectory('d'));
        $this->assertSame([], $this->driver->files('d'));
    }

    // ── Property Hooks ───────────────────────────────────────────

    public function testFileCountHook(): void
    {
        $this->assertSame(0, $this->driver->fileCount);
        $this->driver->put('a.txt', 'x');
        $this->assertSame(1, $this->driver->fileCount);
    }

    public function testTotalBytesHook(): void
    {
        $this->driver->put('a.txt', 'hello');
        $this->assertSame(5, $this->driver->totalBytes);
    }

    // ── URL / Driver ─────────────────────────────────────────────

    public function testUrl(): void
    {
        $this->assertSame('/memory/path/to/file.txt', $this->driver->url('path/to/file.txt'));
    }

    public function testDriverName(): void
    {
        $this->assertSame('memory', $this->driver->getDriver());
    }

    // ── Path Normalization ───────────────────────────────────────

    public function testPathNormalization(): void
    {
        $this->driver->put('//double//slashes//', 'x');
        $this->assertSame('x', $this->driver->get('double/slashes'));
    }
}
