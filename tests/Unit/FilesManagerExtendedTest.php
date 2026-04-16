<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\FilesManager;
use MonkeysLegion\Files\FilesServiceProvider;
use MonkeysLegion\Files\Security\ContentValidator;
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Upload\UploadValidator;
use PHPUnit\Framework\TestCase;

/**
 * Extended FilesManager tests for full branch coverage.
 *
 * @covers \MonkeysLegion\Files\FilesManager
 * @covers \MonkeysLegion\Files\FilesServiceProvider
 */
final class FilesManagerExtendedTest extends TestCase
{
    // ── Upload with ContentValidator ─────────────────────────────

    public function testUploadWithContentValidatorPass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, 'plain text content here');

        $manager = new FilesManager(
            disks: ['local' => new MemoryDriver()],
            contentValidator: new ContentValidator(),
        );

        $file   = new UploadedFile($tmp, 'readme.txt', 'text/plain', 22);
        $result = $manager->upload($file, 'docs');

        $this->assertTrue($result->success);

        unlink($tmp);
    }

    public function testUploadWithContentValidatorBlocksMimeSpoofing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        // Write plain text but claim it's an image
        file_put_contents($tmp, 'plain text pretending to be JPEG');

        $manager = new FilesManager(
            disks: ['local' => new MemoryDriver()],
            contentValidator: new ContentValidator(),
        );

        $file   = new UploadedFile($tmp, 'spoof.jpg', 'image/jpeg', 31);
        $result = $manager->upload($file, 'uploads');

        $this->assertTrue($result->failed);
        $this->assertNotEmpty($result->errors);

        unlink($tmp);
    }

    // ── Upload preserve_name option ──────────────────────────────

    public function testUploadPreserveName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, 'data');

        $manager = new FilesManager(disks: ['local' => new MemoryDriver()]);
        $file    = new UploadedFile($tmp, 'my-file.txt', 'text/plain', 4);
        $result  = $manager->upload($file, 'docs', options: ['preserve_name' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('docs/my-file.txt', $result->file->path);

        unlink($tmp);
    }

    public function testUploadPreserveNameSanitizesPathSegments(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ml_');
        file_put_contents($tmp, 'data');

        $manager = new FilesManager(disks: ['local' => new MemoryDriver()]);
        $file    = new UploadedFile($tmp, '../../unsafe.txt', 'text/plain', 4);
        $result  = $manager->upload($file, 'docs', options: ['preserve_name' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('docs/unsafe.txt', $result->file->path);

        unlink($tmp);
    }

    // ── crossDiskCopy same disk shortcut ─────────────────────────

    public function testCrossDiskCopySameDisk(): void
    {
        $mem = new MemoryDriver();
        $manager = new FilesManager(disks: ['a' => $mem]);

        $manager->put('original.txt', 'data', 'a');

        // Same disk — should use internal copy, not stream
        $this->assertTrue($manager->crossDiskCopy('original.txt', 'copy.txt', 'a', 'a'));
        $this->assertSame('data', $manager->get('copy.txt', 'a'));
    }

    // ── mimeType delegation ─────────────────────────────────────

    public function testMimeType(): void
    {
        $mem = new MemoryDriver();
        $mem->put('data.txt', 'Hello world text content');

        $manager = new FilesManager(disks: ['local' => $mem]);

        $this->assertSame('text/plain', $manager->mimeType('data.txt'));
    }

    // ── ServiceProvider error paths ─────────────────────────────

    public function testServiceProviderUnknownDriver(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unknown driver');

        FilesServiceProvider::create([
            'bad' => ['driver' => 'ftp'],
        ]);
    }

    public function testServiceProviderFromAttributesMissingAnnotation(): void
    {
        $this->expectException(StorageException::class);

        $config = new class {};
        FilesServiceProvider::fromAttributes($config);
    }

    // ── ServiceProvider builds all driver types ──────────────────

    public function testServiceProviderLocalDriver(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ml_sp_test_' . uniqid();
        mkdir($tmpDir, 0o755, true);

        $manager = FilesServiceProvider::create([
            'local' => [
                'driver'    => 'local',
                'root'      => $tmpDir,
                'url'       => 'https://example.com/files',
                'visibility' => 'public',
            ],
        ]);

        $this->assertTrue($manager->disk('local')->put('test.txt', 'data'));

        // Cleanup
        unlink($tmpDir . '/test.txt');
        rmdir($tmpDir);
    }

    public function testServiceProviderFromAttributesWithArray(): void
    {
        $config = new #[\MonkeysLegion\Files\Attributes\StorageConfig('mem')] class {
            #[\MonkeysLegion\Files\Attributes\Disk('mem', 'memory')]
            public function memDisk(): array
            {
                return ['driver' => 'memory', 'visibility' => 'private'];
            }
        };

        $manager = FilesServiceProvider::fromAttributes($config);
        $this->assertTrue($manager->disk('mem')->put('x.txt', 'y'));
        $this->assertSame('y', $manager->disk('mem')->get('x.txt'));
    }
}
