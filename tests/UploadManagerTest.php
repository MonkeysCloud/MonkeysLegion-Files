<?php

namespace MonkeysLegion\Files\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Storage\LocalStorage;
use MonkeysLegion\Files\Upload\HashPathNamer;
use MonkeysLegion\Files\Upload\UploadManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class UploadManagerTest extends TestCase
{
    public function testUpload(): void
    {
        $tmpRoot = sys_get_temp_dir().'/ml-files-test-'.bin2hex(random_bytes(4));
        mkdir($tmpRoot, 0777, true);

        $storage = new LocalStorage($tmpRoot);
        $manager = new UploadManager($storage, new HashPathNamer(), 1024 * 1024, ['text/plain']);

        $stream = Psr7::streamFor('hello');
        $uploaded = new UploadedFile($stream, 5, UPLOAD_ERR_OK, 'hello.txt', 'text/plain');
        $request = new ServerRequest('POST', '/upload');
        $request = $request->withUploadedFiles(['file' => $uploaded]);

        $meta = $manager->handle($request, 'file');

        $this->assertSame('text/plain', $meta->mimeType);
        $this->assertTrue($storage->exists($meta->path));
        $this->assertEquals('hello', (string) $storage->read($meta->path));
    }
}
