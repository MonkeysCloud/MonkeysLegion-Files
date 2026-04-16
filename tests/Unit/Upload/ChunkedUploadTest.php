<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Upload;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Exception\UploadException;
use MonkeysLegion\Files\Upload\ChunkedUpload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Upload\ChunkedUpload
 */
final class ChunkedUploadTest extends TestCase
{
    private MemoryDriver $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryDriver();
    }

    public function testConstructWithInvalidChunks(): void
    {
        $this->expectException(UploadException::class);
        new ChunkedUpload($this->storage, 'upload-1', 0);
    }

    public function testPropertyHooks(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 3);

        $this->assertSame('upload-1', $upload->id);
        $this->assertSame(0, $upload->receivedChunks);
        $this->assertFalse($upload->isComplete);
        $this->assertSame(0.0, $upload->progress);
    }

    public function testAddChunk(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 3);
        $upload->addChunk(0, 'chunk0');

        $this->assertSame(1, $upload->receivedChunks);
        $this->assertSame(33.3, $upload->progress);
    }

    public function testAddChunkOutOfRange(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 2);

        $this->expectException(UploadException::class);
        $upload->addChunk(5, 'data');
    }

    public function testAssembleComplete(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 3);
        $upload->addChunk(0, 'AAA');
        $upload->addChunk(1, 'BBB');
        $upload->addChunk(2, 'CCC');

        $this->assertTrue($upload->isComplete);
        $this->assertSame(100.0, $upload->progress);

        $this->assertTrue($upload->assemble('output.bin'));
        $this->assertSame('AAABBBCCC', $this->storage->get('output.bin'));
    }

    public function testAssembleThrowsIfIncomplete(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 3);
        $upload->addChunk(0, 'AAA');

        $this->expectException(UploadException::class);
        $upload->assemble('output.bin');
    }

    public function testMissingChunks(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 4);
        $upload->addChunk(0, 'A');
        $upload->addChunk(2, 'C');

        $this->assertSame([1, 3], $upload->missingChunks());
    }

    public function testCleanup(): void
    {
        $upload = new ChunkedUpload($this->storage, 'upload-1', 2);
        $upload->addChunk(0, 'A');
        $upload->addChunk(1, 'B');
        $upload->cleanup();

        $this->assertSame(0, $upload->receivedChunks);
    }
}
