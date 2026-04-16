<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Driver;

use MonkeysLegion\Files\Driver\LocalDriver;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\SecurityException;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * Extended LocalDriver tests for full coverage.
 *
 * @covers \MonkeysLegion\Files\Driver\LocalDriver
 */
final class LocalDriverExtendedTest extends TestCase
{
    private string $tmpDir;
    private LocalDriver $driver;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ml_local_ext_' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        $this->driver = new LocalDriver(
            basePath: $this->tmpDir,
            baseUrl: 'https://example.com/files',
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    // ── append / prepend ─────────────────────────────────────────

    public function testAppend(): void
    {
        $this->driver->put('log.txt', 'line1');
        $this->driver->append('log.txt', "\nline2");

        $this->assertSame("line1\nline2", $this->driver->get('log.txt'));
    }

    public function testAppendCreatesFile(): void
    {
        $this->driver->append('new.txt', 'first');
        $this->assertSame('first', $this->driver->get('new.txt'));
    }

    public function testPrepend(): void
    {
        $this->driver->put('log.txt', 'world');
        $this->driver->prepend('log.txt', 'hello ');

        $this->assertSame('hello world', $this->driver->get('log.txt'));
    }

    public function testPrependCreatesFile(): void
    {
        $this->driver->prepend('new.txt', 'content');
        $this->assertSame('content', $this->driver->get('new.txt'));
    }

    // ── putStream / getStream ────────────────────────────────────

    public function testPutStream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'streamed data');
        rewind($stream);

        $this->assertTrue($this->driver->putStream('stream.bin', $stream));
        fclose($stream);

        $this->assertSame('streamed data', $this->driver->get('stream.bin'));
    }

    public function testPutStreamInvalidStream(): void
    {
        $this->expectException(\MonkeysLegion\Files\Exception\StorageException::class);
        $this->driver->putStream('bad.bin', 'not a stream');
    }

    public function testGetStream(): void
    {
        $this->driver->put('read.txt', 'stream contents');

        $stream = $this->driver->getStream('read.txt');
        $this->assertIsResource($stream);
        $this->assertSame('stream contents', stream_get_contents($stream));
        fclose($stream);
    }

    public function testGetStreamNonexistent(): void
    {
        $this->assertNull($this->driver->getStream('nope.txt'));
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function testMimeType(): void
    {
        $this->driver->put('text.txt', 'Hello world text content here');
        $mime = $this->driver->mimeType('text.txt');

        $this->assertSame('text/plain', $mime);
    }

    public function testMimeTypeNonexistent(): void
    {
        $this->assertNull($this->driver->mimeType('nope.txt'));
    }

    public function testLastModified(): void
    {
        $this->driver->put('time.txt', 'data');
        $ts = $this->driver->lastModified('time.txt');

        $this->assertNotNull($ts);
        $this->assertGreaterThan(0, $ts);
    }

    public function testLastModifiedNonexistent(): void
    {
        $this->assertNull($this->driver->lastModified('nope.txt'));
    }

    public function testChecksum(): void
    {
        $this->driver->put('hash.txt', 'testdata');

        $sha256 = $this->driver->checksum('hash.txt');
        $this->assertSame(hash('sha256', 'testdata'), $sha256);

        $md5 = $this->driver->checksum('hash.txt', 'md5');
        $this->assertSame(md5('testdata'), $md5);
    }

    public function testChecksumNonexistent(): void
    {
        $this->assertNull($this->driver->checksum('nope.txt'));
    }

    // ── Visibility ───────────────────────────────────────────────

    public function testVisibility(): void
    {
        $this->driver->put('vis.txt', 'x');
        $vis = $this->driver->visibility('vis.txt');
        $this->assertInstanceOf(Visibility::class, $vis);
    }

    public function testSetVisibility(): void
    {
        $this->driver->put('vis.txt', 'x');
        $this->driver->setVisibility('vis.txt', Visibility::Public);
        // Private visibility test
        $this->driver->setVisibility('vis.txt', Visibility::Private);

        $this->assertTrue(true); // No exception thrown
    }

    public function testVisibilityNonexistent(): void
    {
        $this->assertNull($this->driver->visibility('nope.txt'));
    }

    // ── URL ──────────────────────────────────────────────────────

    public function testUrl(): void
    {
        $url = $this->driver->url('path/file.jpg');
        $this->assertSame('https://example.com/files/path/file.jpg', $url);
    }

    // ── Directory Operations ────────────────────────────────────

    public function testMakeDirectory(): void
    {
        $this->assertTrue($this->driver->makeDirectory('subdir/nested'));
        $this->assertDirectoryExists($this->tmpDir . '/subdir/nested');
    }

    public function testDeleteDirectory(): void
    {
        $this->driver->put('deleteme/a.txt', 'x');
        $this->driver->put('deleteme/b.txt', 'y');

        $this->assertTrue($this->driver->deleteDirectory('deleteme'));
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/deleteme');
    }

    public function testDirectories(): void
    {
        $this->driver->put('root/alpha/a.txt', 'x');
        $this->driver->put('root/beta/b.txt', 'y');

        $dirs = $this->driver->directories('root');

        $this->assertCount(2, $dirs);
    }

    public function testFilesRecursive(): void
    {
        $this->driver->put('dir/a.txt', 'a');
        $this->driver->put('dir/sub/b.txt', 'b');

        $files = $this->driver->files('dir', recursive: true);

        $this->assertCount(2, $files);
    }

    public function testFilesNonRecursive(): void
    {
        $this->driver->put('dir/a.txt', 'a');
        $this->driver->put('dir/sub/b.txt', 'b');

        $files = $this->driver->files('dir', recursive: false);

        $this->assertCount(1, $files);
    }

    // ── getDriver ────────────────────────────────────────────────

    public function testGetDriver(): void
    {
        $this->assertSame('local', $this->driver->getDriver());
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
