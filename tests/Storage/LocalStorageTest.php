<?php

namespace MonkeysLegion\Files\Tests\Storage;

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Storage\LocalStorage;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase
{
    private string $root;
    private LocalStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ml-files-local-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->storage = new LocalStorage($this->root, 'https://cdn.example.com');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $scan = scandir($dir);
        foreach ($scan as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testName(): void
    {
        $this->assertEquals('local', $this->storage->name());
    }

    public function testPutAndExists(): void
    {
        $stream = Psr7::streamFor('hello world');
        $url = $this->storage->put('test.txt', $stream);

        $this->assertEquals('https://cdn.example.com/test.txt', $url);
        $this->assertTrue($this->storage->exists('test.txt'));
        $this->assertFileExists($this->root . '/test.txt');
        $this->assertStringEqualsFile($this->root . '/test.txt', 'hello world');
    }

    public function testPutCreatesDirectories(): void
    {
        $stream = Psr7::streamFor('content');
        $this->storage->put('a/b/c.txt', $stream);

        $this->assertFileExists($this->root . '/a/b/c.txt');
    }

    public function testRead(): void
    {
        file_put_contents($this->root . '/read.txt', 'content');
        $stream = $this->storage->read('read.txt');
        $this->assertEquals('content', (string) $stream);
    }

    public function testDelete(): void
    {
        file_put_contents($this->root . '/del.txt', 'content');
        $this->storage->delete('del.txt');
        $this->assertFalse($this->storage->exists('del.txt'));
        $this->assertFileDoesNotExist($this->root . '/del.txt');
    }

    public function testPublicUrl(): void
    {
        $this->assertEquals('https://cdn.example.com/foo.jpg', $this->storage->publicUrl('foo.jpg'));
    }

    public function testNoPublicUrl(): void
    {
        $storage = new LocalStorage($this->root);
        $this->assertNull($storage->put('foo.txt', Psr7::streamFor('')));
        $this->assertNull($storage->publicUrl('foo.txt'));
    }
}
