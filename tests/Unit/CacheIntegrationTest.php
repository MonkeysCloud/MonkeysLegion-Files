<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit;

use MonkeysLegion\Cache\Stores\ArrayStore;
use MonkeysLegion\Files\Cdn\CdnUrlGenerator;
use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\FilesManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ML Cache 2.0 integration in FilesManager and CdnUrlGenerator.
 *
 * @covers \MonkeysLegion\Files\FilesManager
 * @covers \MonkeysLegion\Files\Cdn\CdnUrlGenerator
 */
final class CacheIntegrationTest extends TestCase
{
    private ArrayStore $cache;
    private MemoryDriver $driver;
    private FilesManager $manager;

    protected function setUp(): void
    {
        $this->cache  = new ArrayStore();
        $this->driver = new MemoryDriver();

        $this->manager = new FilesManager(
            disks: ['local' => $this->driver],
            defaultDisk: 'local',
            cache: $this->cache,
            cacheTtl: 120,
        );
    }

    // ── $isCacheEnabled hook ─────────────────────────────────────

    public function testIsCacheEnabledHook(): void
    {
        $this->assertTrue($this->manager->isCacheEnabled);

        $noCache = new FilesManager(disks: ['local' => new MemoryDriver()]);
        $this->assertFalse($noCache->isCacheEnabled);
    }

    // ── Metadata caching ─────────────────────────────────────────

    public function testSizeIsCached(): void
    {
        $this->manager->put('file.txt', 'hello world');

        // First call: computes and caches
        $size1 = $this->manager->size('file.txt');
        $this->assertSame(11, $size1);

        // Verify it's in the cache
        $this->assertNotEmpty($this->cache->all());

        // Second call: served from cache
        $size2 = $this->manager->size('file.txt');
        $this->assertSame(11, $size2);
    }

    public function testMimeTypeIsCached(): void
    {
        $this->manager->put('data.txt', 'Hello world text content');

        $mime1 = $this->manager->mimeType('data.txt');
        $mime2 = $this->manager->mimeType('data.txt');

        $this->assertSame($mime1, $mime2);
        $this->assertSame('text/plain', $mime1);
    }

    public function testChecksumIsCached(): void
    {
        $this->manager->put('hash.txt', 'data');

        $hash1 = $this->manager->checksum('hash.txt');
        $hash2 = $this->manager->checksum('hash.txt');

        $this->assertSame($hash1, $hash2);
        $this->assertSame(hash('sha256', 'data'), $hash1);
    }

    public function testChecksumWithDifferentAlgo(): void
    {
        $this->manager->put('hash.txt', 'data');

        $sha = $this->manager->checksum('hash.txt', 'sha256');
        $md5 = $this->manager->checksum('hash.txt', 'md5');

        $this->assertNotSame($sha, $md5);
        $this->assertSame(hash('sha256', 'data'), $sha);
        $this->assertSame(md5('data'), $md5);
    }

    // ── Cache invalidation on write ─────────────────────────────

    public function testPutInvalidatesCache(): void
    {
        $this->manager->put('cached.txt', 'original');
        $this->assertSame(8, $this->manager->size('cached.txt'));

        // Overwrite — should invalidate
        $this->manager->put('cached.txt', 'updated content here');
        $this->assertSame(20, $this->manager->size('cached.txt'));
    }

    public function testPutStreamInvalidatesCache(): void
    {
        $this->manager->put('stream.txt', 'before');
        $this->assertSame(6, $this->manager->size('stream.txt'));

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'after stream write');
        rewind($stream);

        $this->manager->putStream('stream.txt', $stream);
        fclose($stream);

        // Cache should be invalidated — return new size
        $this->assertSame(18, $this->manager->size('stream.txt'));
    }

    // ── Cache invalidation on delete ────────────────────────────

    public function testDeleteInvalidatesCache(): void
    {
        $this->manager->put('gone.txt', 'temporary');
        $this->assertSame(9, $this->manager->size('gone.txt'));

        $this->manager->delete('gone.txt');

        // After delete, size should return null (not the cached 9)
        $this->assertNull($this->manager->size('gone.txt'));
    }

    // ── Cache invalidation on move ──────────────────────────────

    public function testMoveInvalidatesCache(): void
    {
        $this->manager->put('source.txt', 'moving');
        $this->assertSame(6, $this->manager->size('source.txt'));

        $this->manager->move('source.txt', 'dest.txt');

        $this->assertNull($this->manager->size('source.txt'));
        $this->assertSame(6, $this->manager->size('dest.txt'));
    }

    // ── Cache invalidation on copy ──────────────────────────────

    public function testCopyInvalidatesDestinationCache(): void
    {
        $this->manager->put('src.txt', 'content');
        $this->manager->put('dst.txt', 'old');

        // Cache the old destination size
        $this->assertSame(3, $this->manager->size('dst.txt'));

        // Copy over
        $this->manager->copy('src.txt', 'dst.txt');

        // New size should reflect the copy
        $this->assertSame(7, $this->manager->size('dst.txt'));
    }

    // ── flushMetadataCache ──────────────────────────────────────

    public function testFlushMetadataCache(): void
    {
        $this->manager->put('a.txt', 'aaa');
        $this->manager->put('b.txt', 'bbb');

        // Populate cache
        $this->manager->size('a.txt');
        $this->manager->size('b.txt');
        $this->assertNotEmpty($this->cache->all());

        // Flush
        $this->assertTrue($this->manager->flushMetadataCache());
        $this->assertEmpty($this->cache->all());
    }

    public function testFlushMetadataCacheWithoutCache(): void
    {
        $manager = new FilesManager(disks: ['local' => new MemoryDriver()]);
        $this->assertFalse($manager->flushMetadataCache());
    }

    // ── Without cache (passthrough) ─────────────────────────────

    public function testMetadataWorksWithoutCache(): void
    {
        $manager = new FilesManager(disks: ['local' => $this->driver]);

        $manager->put('nocache.txt', 'hello');

        $this->assertSame(5, $manager->size('nocache.txt'));
        $this->assertSame('text/plain', $manager->mimeType('nocache.txt'));
        $this->assertSame(hash('sha256', 'hello'), $manager->checksum('nocache.txt'));
    }

    // ── CdnUrlGenerator with cache ──────────────────────────────

    public function testCdnVersionedUrlIsCached(): void
    {
        $this->driver->put('img.png', 'image data');

        $cdn  = new CdnUrlGenerator(cdnBaseUrl: 'https://cdn.example.com', cache: $this->cache);
        $url1 = $cdn->versionedUrl($this->driver, 'img.png');
        $url2 = $cdn->versionedUrl($this->driver, 'img.png');

        $this->assertSame($url1, $url2);
        $this->assertStringStartsWith('https://cdn.example.com/img.png?v=', $url1);
    }

    public function testCdnVersionedUrlWithoutCache(): void
    {
        $this->driver->put('img.png', 'image data');

        $cdn = new CdnUrlGenerator(cdnBaseUrl: 'https://cdn.example.com');
        $url = $cdn->versionedUrl($this->driver, 'img.png');

        $this->assertStringStartsWith('https://cdn.example.com/img.png?v=', $url);
    }

    public function testCdnInvalidateVersionedUrl(): void
    {
        $this->driver->put('img.png', 'v1');

        $cdn  = new CdnUrlGenerator(cdnBaseUrl: 'https://cdn.example.com', cache: $this->cache);
        $url1 = $cdn->versionedUrl($this->driver, 'img.png');

        // Update file content
        $this->driver->put('img.png', 'v2 updated content');

        // Invalidate
        $cdn->invalidateVersionedUrl($this->driver, 'img.png');

        // New URL should be different (different checksum)
        $url2 = $cdn->versionedUrl($this->driver, 'img.png');
        $this->assertNotSame($url1, $url2);
    }
}
