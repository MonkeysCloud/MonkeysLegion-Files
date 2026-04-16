<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Driver;

use MonkeysLegion\Files\Driver\LocalDriver;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\SecurityException;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Driver\LocalDriver
 */
final class LocalDriverTest extends TestCase
{
    private string $basePath;
    private LocalDriver $driver;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/ml_files_test_' . bin2hex(random_bytes(4));
        $this->driver = new LocalDriver(
            basePath: $this->basePath,
            baseUrl: '/files',
        );
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->basePath);
    }

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($path);
    }

    // ── Put / Get ────────────────────────────────────────────────

    public function testPutAndGet(): void
    {
        $this->assertTrue($this->driver->put('test.txt', 'hello world'));
        $this->assertSame('hello world', $this->driver->get('test.txt'));
    }

    public function testPutCreatesDirectories(): void
    {
        $this->driver->put('deep/nested/dir/file.txt', 'x');
        $this->assertSame('x', $this->driver->get('deep/nested/dir/file.txt'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->driver->get('nope.txt'));
    }

    public function testPutStream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'streamed');
        rewind($stream);

        $this->assertTrue($this->driver->putStream('s.txt', $stream));
        fclose($stream);

        $this->assertSame('streamed', $this->driver->get('s.txt'));
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

    // ── Delete ───────────────────────────────────────────────────

    public function testDelete(): void
    {
        $this->driver->put('del.txt', 'bye');
        $this->assertTrue($this->driver->delete('del.txt'));
        $this->assertFalse($this->driver->exists('del.txt'));
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertTrue($this->driver->delete('nope.txt'));
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
        $this->driver->put('plain.txt', 'Hello world');
        $this->assertSame('text/plain', $this->driver->mimeType('plain.txt'));
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
        $this->driver->put('pub.txt', 'x');
        $this->assertSame(Visibility::Public, $this->driver->visibility('pub.txt'));
    }

    public function testSetVisibility(): void
    {
        $this->driver->put('v.txt', 'x');
        $this->driver->setVisibility('v.txt', Visibility::Private);
        $this->assertSame(Visibility::Private, $this->driver->visibility('v.txt'));
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
        $this->driver->copy('missing.txt', 'b.txt');
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

    public function testMakeDirectory(): void
    {
        $this->assertTrue($this->driver->makeDirectory('new/dir'));
        $this->assertDirectoryExists($this->basePath . '/new/dir');
    }

    public function testDeleteDirectory(): void
    {
        $this->driver->put('d/a.txt', '1');
        $this->driver->put('d/sub/b.txt', '2');

        $this->assertTrue($this->driver->deleteDirectory('d'));
        $this->assertDirectoryDoesNotExist($this->basePath . '/d');
    }

    // ── URL ──────────────────────────────────────────────────────

    public function testUrl(): void
    {
        $this->assertSame('/files/path/to/file.txt', $this->driver->url('path/to/file.txt'));
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function testDriverName(): void
    {
        $this->assertSame('local', $this->driver->getDriver());
    }

    // ── Security ─────────────────────────────────────────────────

    public function testPathTraversalPrevention(): void
    {
        // Create a subdirectory so the parent exists and realpath works
        $this->driver->put('sub/legit.txt', 'safe');

        $this->expectException(SecurityException::class);
        // Navigate up from "sub" to above basePath
        $this->driver->get('sub/../../../../../../etc/passwd');
    }
}
