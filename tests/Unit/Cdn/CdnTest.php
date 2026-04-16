<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Cdn;

use MonkeysLegion\Files\Cdn\CdnUrlGenerator;
use MonkeysLegion\Files\Cdn\SignedUrl;
use MonkeysLegion\Files\Driver\MemoryDriver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Cdn\CdnUrlGenerator
 * @covers \MonkeysLegion\Files\Cdn\SignedUrl
 */
final class CdnTest extends TestCase
{
    // ── SignedUrl ─────────────────────────────────────────────────

    public function testSignedUrlProperties(): void
    {
        $url = new SignedUrl(
            url: 'https://cdn.example.com/file.jpg?sig=abc',
            expiresAt: new \DateTimeImmutable('+1 hour'),
            path: 'file.jpg',
            disk: 's3',
        );

        $this->assertFalse($url->isExpired);
        $this->assertGreaterThan(3500, $url->ttl);
        $this->assertSame('https://cdn.example.com/file.jpg?sig=abc', (string) $url);
    }

    public function testSignedUrlExpired(): void
    {
        $url = new SignedUrl(
            url: 'https://cdn.example.com/old.jpg',
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );

        $this->assertTrue($url->isExpired);
        $this->assertSame(0, $url->ttl);
    }

    // ── CdnUrlGenerator ─────────────────────────────────────────

    public function testUrlWithCdn(): void
    {
        $cdn    = new CdnUrlGenerator('https://cdn.example.com');
        $driver = new MemoryDriver();

        $this->assertSame('https://cdn.example.com/path/file.jpg', $cdn->url($driver, 'path/file.jpg'));
    }

    public function testUrlWithoutCdn(): void
    {
        $cdn    = new CdnUrlGenerator();
        $driver = new MemoryDriver();

        $url = $cdn->url($driver, 'path/file.jpg');
        $this->assertStringContainsString('path/file.jpg', $url);
    }

    public function testVersionedUrl(): void
    {
        $cdn    = new CdnUrlGenerator('https://cdn.example.com');
        $driver = new MemoryDriver();
        $driver->put('file.txt', 'hello');

        $url = $cdn->versionedUrl($driver, 'file.txt');
        $this->assertStringContainsString('?v=', $url);
    }

    public function testSignedUrlGeneration(): void
    {
        $cdn    = new CdnUrlGenerator();
        $driver = new MemoryDriver();

        $signed = $cdn->signedUrl($driver, 'secure/file.pdf', 7200);

        $this->assertInstanceOf(SignedUrl::class, $signed);
        $this->assertFalse($signed->isExpired);
        $this->assertSame('memory', $signed->disk);
        $this->assertSame('secure/file.pdf', $signed->path);
    }
}
