<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Driver;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * Extended MemoryDriver tests for full coverage.
 *
 * @covers \MonkeysLegion\Files\Driver\MemoryDriver
 */
final class MemoryDriverExtendedTest extends TestCase
{
    private MemoryDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new MemoryDriver();
    }

    // ── append / prepend coverage ────────────────────────────────

    public function testAppendCreatesNew(): void
    {
        $this->driver->append('log.txt', 'initial');
        $this->assertSame('initial', $this->driver->get('log.txt'));
    }

    public function testAppendExisting(): void
    {
        $this->driver->put('log.txt', 'line1');
        $this->driver->append('log.txt', ' line2');

        $this->assertSame('line1 line2', $this->driver->get('log.txt'));
    }

    public function testPrependCreatesNew(): void
    {
        $this->driver->prepend('log.txt', 'content');
        $this->assertSame('content', $this->driver->get('log.txt'));
    }

    public function testPrependExisting(): void
    {
        $this->driver->put('log.txt', 'world');
        $this->driver->prepend('log.txt', 'hello ');

        $this->assertSame('hello world', $this->driver->get('log.txt'));
    }

    // ── putStream edge cases ─────────────────────────────────────

    public function testPutStreamInvalid(): void
    {
        $this->assertFalse($this->driver->putStream('bad.bin', 'not a stream'));
    }

    public function testPutStreamValid(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'stream content');
        rewind($stream);

        $this->assertTrue($this->driver->putStream('s.bin', $stream));
        fclose($stream);

        $this->assertSame('stream content', $this->driver->get('s.bin'));
    }

    // ── getStream ────────────────────────────────────────────────

    public function testGetStreamReturnsNull(): void
    {
        $this->assertNull($this->driver->getStream('nope.txt'));
    }

    public function testGetStreamReturnsResource(): void
    {
        $this->driver->put('data.txt', 'hello');
        $stream = $this->driver->getStream('data.txt');

        $this->assertIsResource($stream);
        $this->assertSame('hello', stream_get_contents($stream));
        fclose($stream);
    }

    // ── Metadata edge cases ──────────────────────────────────────

    public function testSizeNonexistent(): void
    {
        $this->assertNull($this->driver->size('nope.txt'));
    }

    public function testMimeType(): void
    {
        $this->driver->put('data.txt', 'Hello world text content');
        $mime = $this->driver->mimeType('data.txt');

        $this->assertSame('text/plain', $mime);
    }

    public function testMimeTypeNonexistent(): void
    {
        $this->assertNull($this->driver->mimeType('nope.txt'));
    }

    public function testLastModifiedNonexistent(): void
    {
        $this->assertNull($this->driver->lastModified('nope.txt'));
    }

    public function testChecksumAlgorithm(): void
    {
        $this->driver->put('file.txt', 'data');

        $sha = $this->driver->checksum('file.txt', 'sha256');
        $md5 = $this->driver->checksum('file.txt', 'md5');

        $this->assertSame(hash('sha256', 'data'), $sha);
        $this->assertSame(md5('data'), $md5);
    }

    public function testChecksumNonexistent(): void
    {
        $this->assertNull($this->driver->checksum('nope.txt'));
    }

    // ── Visibility edge cases ────────────────────────────────────

    public function testVisibilityNonexistent(): void
    {
        $this->assertNull($this->driver->visibility('nope.txt'));
    }

    public function testSetVisibilityThrowsForMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->driver->setVisibility('nope.txt', Visibility::Public);
    }

    public function testDefaultVisibility(): void
    {
        $driver = new MemoryDriver(defaultVisibility: Visibility::Public);
        $driver->put('file.txt', 'x');

        $this->assertSame(Visibility::Public, $driver->visibility('file.txt'));
    }

    // ── copy edge cases ──────────────────────────────────────────

    public function testCopyThrowsForMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->driver->copy('nope.txt', 'dest.txt');
    }

    public function testCopyPreservesVisibility(): void
    {
        $this->driver->put('src.txt', 'data');
        $this->driver->setVisibility('src.txt', Visibility::Public);

        $this->driver->copy('src.txt', 'dst.txt');

        $this->assertSame(Visibility::Public, $this->driver->visibility('dst.txt'));
    }

    // ── Directory operations ─────────────────────────────────────

    public function testDirectoriesEmpty(): void
    {
        $this->assertSame([], $this->driver->directories());
    }

    public function testFilesEmpty(): void
    {
        $this->assertSame([], $this->driver->files());
    }

    public function testFilesRoot(): void
    {
        $this->driver->put('a.txt', 'x');
        $this->driver->put('b.txt', 'y');

        $files = $this->driver->files();
        $this->assertCount(2, $files);
    }

    public function testFilesRecursive(): void
    {
        $this->driver->put('a.txt', 'x');
        $this->driver->put('dir/b.txt', 'y');
        $this->driver->put('dir/sub/c.txt', 'z');

        $files = $this->driver->files('', recursive: true);
        $this->assertCount(3, $files);
    }

    public function testFilesNonRecursive(): void
    {
        $this->driver->put('a.txt', 'x');
        $this->driver->put('dir/b.txt', 'y');

        $files = $this->driver->files('', recursive: false);
        $this->assertCount(1, $files);
    }

    public function testMakeDirectoryNoop(): void
    {
        $this->assertTrue($this->driver->makeDirectory('anything'));
    }

    public function testDeleteDirectoryRemovesChildren(): void
    {
        $this->driver->put('dir/a.txt', 'x');
        $this->driver->put('dir/b.txt', 'y');
        $this->driver->put('other.txt', 'z');

        $this->assertTrue($this->driver->deleteDirectory('dir'));
        $this->assertNull($this->driver->get('dir/a.txt'));
        $this->assertNull($this->driver->get('dir/b.txt'));
        $this->assertSame('z', $this->driver->get('other.txt'));
    }

    // ── path normalization ───────────────────────────────────────

    public function testPathNormalization(): void
    {
        $this->driver->put('/leading/slash.txt', 'x');
        $this->assertSame('x', $this->driver->get('leading/slash.txt'));
    }

    public function testPathDoubleSlash(): void
    {
        $this->driver->put('path//double//slash.txt', 'y');
        $this->assertSame('y', $this->driver->get('path/double/slash.txt'));
    }
}
