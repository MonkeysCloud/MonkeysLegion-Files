<?php

namespace MonkeysLegion\Files\Tests\Image;

use MonkeysLegion\Files\Tests\TestCase;
use MonkeysLegion\Files\Image\ImageProcessor;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\ImageProcessingException;
use Mockery;

class ImageProcessorTest extends TestCase
{
    private $storage;
    private $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->processor = new ImageProcessor('gd');
    }

    public function testThumbnailResize()
    {
        $sourcePath = 'images/source.jpg';
        $sourceData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='); // 1x1 red pixel

        $this->storage->shouldReceive('get')
            ->with($sourcePath)
            ->once()
            ->andReturn($sourceData);

        $this->storage->shouldReceive('put')
            ->once()
            ->andReturn('images/source_thumb_100x100.jpg');

        $result = $this->processor->thumbnail($this->storage, $sourcePath, 100, 100);

        $this->assertEquals('images/source_thumb_100x100.jpg', $result);
    }

    public function testInvalidDriverThrowsException()
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is loaded, cannot test missing driver exception');
        }

        $this->expectException(ImageProcessingException::class);
        new ImageProcessor('imagick');
    }
}
