<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Files\Attributes\Disk;
use MonkeysLegion\Files\Attributes\StorageConfig;
use MonkeysLegion\Files\Attributes\Storable;
use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Event\UploadCompleted;
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\FilesServiceProvider;
use MonkeysLegion\Files\Security\PathValidator;
use MonkeysLegion\Files\Security\VirusScanner;
use MonkeysLegion\Files\Security\ScanResult;
use MonkeysLegion\Files\Contracts\VirusScannerInterface;
use MonkeysLegion\Files\Exception\SecurityException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all remaining components: Attributes, ServiceProvider,
 * PathValidator, VirusScanner, UploadCompleted event.
 *
 * @covers \MonkeysLegion\Files\Attributes\Disk
 * @covers \MonkeysLegion\Files\Attributes\StorageConfig
 * @covers \MonkeysLegion\Files\Attributes\Storable
 * @covers \MonkeysLegion\Files\FilesServiceProvider
 * @covers \MonkeysLegion\Files\Security\PathValidator
 * @covers \MonkeysLegion\Files\Security\VirusScanner
 * @covers \MonkeysLegion\Files\Event\UploadCompleted
 */
final class ComponentsTest extends TestCase
{
    // ── Attributes ───────────────────────────────────────────────

    public function testDiskAttribute(): void
    {
        $disk = new Disk('s3', 's3');
        $this->assertSame('s3', $disk->name);
        $this->assertSame('s3', $disk->driver);
    }

    public function testDiskAttributeDefaultDriver(): void
    {
        $disk = new Disk('local');
        $this->assertSame('local', $disk->driver);
    }

    public function testStorageConfigAttribute(): void
    {
        $config = new StorageConfig('s3');
        $this->assertSame('s3', $config->defaultDisk);
    }

    public function testStorageConfigDefault(): void
    {
        $config = new StorageConfig();
        $this->assertSame('local', $config->defaultDisk);
    }

    public function testStorableAttribute(): void
    {
        $storable = new Storable(disk: 's3', path: 'avatars', collection: 'profile');
        $this->assertSame('s3', $storable->disk);
        $this->assertSame('avatars', $storable->path);
        $this->assertSame('profile', $storable->collection);
    }

    // ── ServiceProvider ──────────────────────────────────────────

    public function testCreateFromArray(): void
    {
        $manager = FilesServiceProvider::create([
            'local' => [
                'driver'    => 'memory',
                'visibility' => 'private',
            ],
        ], 'local');

        $this->assertTrue($manager->disk('local')->put('test.txt', 'hi'));
        $this->assertSame('hi', $manager->get('test.txt'));
    }

    public function testCreateMultipleDisks(): void
    {
        $manager = FilesServiceProvider::create([
            'primary'  => ['driver' => 'memory'],
            'backup'   => ['driver' => 'memory'],
        ], 'primary');

        $this->assertSame(2, $manager->diskCount);
    }

    public function testFromAttributes(): void
    {
        $config = new #[StorageConfig('mem')] class {
            #[Disk('mem', 'memory')]
            public function memDisk(): MemoryDriver
            {
                return new MemoryDriver();
            }
        };

        $manager = FilesServiceProvider::fromAttributes($config);
        $this->assertTrue($manager->disk('mem')->put('x.txt', 'y'));
    }

    // ── PathValidator ────────────────────────────────────────────

    public function testPathValidatorPassesSafePath(): void
    {
        $validator = new PathValidator();
        $basePath  = sys_get_temp_dir();

        $result = $validator->validate('safe/path/file.txt', $basePath);
        $this->assertStringContainsString('safe/path/file.txt', $result);
    }

    public function testPathValidatorBlocksTraversal(): void
    {
        $validator = new PathValidator();

        $this->expectException(SecurityException::class);
        $validator->validate('../etc/passwd', sys_get_temp_dir());
    }

    public function testPathValidatorBlocksNullByte(): void
    {
        $validator = new PathValidator();

        $this->expectException(SecurityException::class);
        $validator->validate("file\0.php", sys_get_temp_dir());
    }

    // ── VirusScanner (composite) ─────────────────────────────────

    public function testVirusScannerNoScanners(): void
    {
        $scanner = new VirusScanner();

        $result = $scanner->scan('/dev/null');
        $this->assertTrue($result->isClean);
        $this->assertSame('none', $result->scanner);
        $this->assertFalse($scanner->isAvailable());
    }

    public function testVirusScannerWithMock(): void
    {
        $mock = new class implements VirusScannerInterface {
            public function scan(string $path): ScanResult
            {
                return new ScanResult(isClean: true, scanner: 'mock');
            }

            public function scanStream(mixed $stream): ScanResult
            {
                return new ScanResult(isClean: true, scanner: 'mock');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'mock';
            }
        };

        $scanner = new VirusScanner($mock);

        $this->assertTrue($scanner->isAvailable());
        $this->assertStringContainsString('mock', $scanner->getName());

        $result = $scanner->scan('/tmp/test');
        $this->assertTrue($result->isClean);
        $this->assertSame('mock', $result->scanner);
    }

    // ── UploadCompleted Event ────────────────────────────────────

    public function testUploadCompletedEvent(): void
    {
        $file = new FileRecord('s3', 'doc.pdf', 'doc.pdf', 'application/pdf', 4096);

        $event = new UploadCompleted(file: $file, disk: 's3', chunked: true);

        $this->assertSame('s3', $event->disk);
        $this->assertTrue($event->chunked);
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    // ── VirusScanner hooks ───────────────────────────────────────

    public function testVirusScannerCountHooks(): void
    {
        $mock = new class implements VirusScannerInterface {
            public function scan(string $path): ScanResult { return new ScanResult(isClean: true, scanner: 'mock'); }
            public function scanStream(mixed $stream): ScanResult { return new ScanResult(isClean: true, scanner: 'mock'); }
            public function isAvailable(): bool { return true; }
            public function getName(): string { return 'mock'; }
        };

        $scanner = new VirusScanner($mock);
        $this->assertSame(1, $scanner->scannerCount);
        $this->assertSame(1, $scanner->availableCount);
    }

    // ── FilesManager hooks ───────────────────────────────────────

    public function testFilesManagerDiskNamesHook(): void
    {
        $manager = FilesServiceProvider::create([
            'primary' => ['driver' => 'memory'],
            'backup'  => ['driver' => 'memory'],
        ], 'primary');

        $this->assertSame(['primary', 'backup'], $manager->diskNames);
        $this->assertSame('primary', $manager->defaultDiskName);
        $this->assertFalse($manager->hasValidator);
        $this->assertFalse($manager->hasContentValidator);
    }

    // ── ValidationException hooks ────────────────────────────────

    public function testValidationExceptionHooks(): void
    {
        $e = new \MonkeysLegion\Files\Exception\ValidationException(['err1', 'err2']);

        $this->assertSame(2, $e->errorCount);
        $this->assertFalse($e->isSingleError);
        $this->assertSame('err1', $e->firstError);
    }

    public function testValidationExceptionSingleError(): void
    {
        $e = new \MonkeysLegion\Files\Exception\ValidationException(['only one']);

        $this->assertTrue($e->isSingleError);
    }

    // ── FileNotFoundException hook ───────────────────────────────

    public function testFileNotFoundExceptionPathHook(): void
    {
        $e = new \MonkeysLegion\Files\Exception\FileNotFoundException('missing/file.txt');

        $this->assertSame('missing/file.txt', $e->filePath);
    }
}
