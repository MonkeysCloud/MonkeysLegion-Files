<?php

namespace MonkeysLegion\Files\Tests;

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileNamer;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\DTO\FileMeta;
use MonkeysLegion\Files\Upload\UploadManager;
use MonkeysLegion\Tests\TestState;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class HelpersTest extends TestCase
{
    private $storage;
    private $namer;
    private $manager;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FileStorage::class);
        $this->storage->method('name')->willReturn('local'); // Ensure name() works if called
        $this->namer = $this->createMock(FileNamer::class);
        
        // UploadManager is final, so we instantiate it with mocks
        $this->manager = new UploadManager($this->storage, $this->namer, 1024);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [FileStorage::class, $this->storage],
            [FileNamer::class, $this->namer],
            [UploadManager::class, $this->manager],
        ]);

        TestState::$container = $container;
        TestState::$config = ['files' => ['signing_key' => 'secret']];
    }

    protected function tearDown(): void
    {
        TestState::$container = null;
        TestState::$config = [];
    }

    public function testMlFilesStorage(): void
    {
        $this->assertSame($this->storage, ml_files_storage());
    }

    public function testMlFilesNamer(): void
    {
        $this->assertSame($this->namer, ml_files_namer());
    }

    public function testMlUploadManager(): void
    {
        $this->assertSame($this->manager, ml_upload_manager());
    }

    public function testMlFilesPut(): void
    {
        $this->namer->method('path')->willReturn('a/b/c.txt');
        $this->storage->method('name')->willReturn('local');
        $this->storage->expects($this->once())->method('put')->willReturn('http://cdn/a/b/c.txt');

        $stream = Psr7::streamFor('content');
        $meta = ml_files_put($stream, 'test.txt');

        $this->assertInstanceOf(FileMeta::class, $meta);
        $this->assertEquals('a/b/c.txt', $meta->path);
        $this->assertEquals('http://cdn/a/b/c.txt', $meta->url);
    }

    public function testMlFilesDelete(): void
    {
        $this->storage->expects($this->once())->method('delete')->with('path/to/file');
        ml_files_delete('path/to/file');
    }

    public function testMlFilesExists(): void
    {
        $this->storage->method('exists')->with('file')->willReturn(true);
        $this->assertTrue(ml_files_exists('file'));
    }

    public function testMlFilesReadStream(): void
    {
        $stream = Psr7::streamFor('data');
        $this->storage->method('read')->with('file')->willReturn($stream);
        $this->assertSame($stream, ml_files_read_stream('file'));
    }

    public function testSignature(): void
    {
        $signed = ml_files_sign_url('/foo', 3600);
        $this->assertStringContainsString('/foo?exp=', $signed);
        $this->assertStringContainsString('&sig=', $signed);
        
        $this->assertTrue(ml_files_verify_signature($signed));
        
        // Test tampering
        $tampered = $signed . 'hack';
        $this->assertFalse(ml_files_verify_signature($tampered));
    }
}
