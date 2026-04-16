<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Maintenance;

use MonkeysLegion\Files\Driver\MemoryDriver;
use MonkeysLegion\Files\Maintenance\GarbageCollector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Maintenance\GarbageCollector
 * @covers \MonkeysLegion\Files\Maintenance\GarbageCollectionResult
 */
final class GarbageCollectorTest extends TestCase
{
    public function testCollectFindsOrphans(): void
    {
        $driver = new MemoryDriver();
        $driver->put('tracked.txt', 'ok');
        $driver->put('orphan1.txt', 'stale');
        $driver->put('orphan2.txt', 'stale');

        $gc     = new GarbageCollector();
        $result = $gc->collect($driver, ['tracked.txt'], dryRun: true);

        $this->assertSame(3, $result->scanned);
        $this->assertSame(2, $result->orphans);
        $this->assertSame(0, $result->deleted); // dry run
        $this->assertTrue($result->dryRun);
        $this->assertTrue($result->hasOrphans);
        $this->assertCount(2, $result->orphanPaths);
    }

    public function testCollectDeletesOrphans(): void
    {
        $driver = new MemoryDriver();
        $driver->put('keep.txt', 'ok');
        $driver->put('orphan.txt', 'stale');

        $gc     = new GarbageCollector();
        $result = $gc->collect($driver, ['keep.txt']);

        $this->assertSame(1, $result->deleted);
        $this->assertFalse($result->dryRun);
        $this->assertNull($driver->get('orphan.txt'));
        $this->assertSame('ok', $driver->get('keep.txt'));
    }

    public function testHumanFreedHook(): void
    {
        $driver = new MemoryDriver();
        $driver->put('tracked.txt', 'ok');
        $driver->put('orphan.txt', str_repeat('x', 2048));

        $gc     = new GarbageCollector();
        $result = $gc->collect($driver, ['tracked.txt'], dryRun: true);

        $this->assertSame('2 KB', $result->humanFreed);
    }

    public function testNoOrphans(): void
    {
        $driver = new MemoryDriver();
        $driver->put('a.txt', 'ok');

        $gc     = new GarbageCollector();
        $result = $gc->collect($driver, ['a.txt']);

        $this->assertSame(0, $result->orphans);
        $this->assertFalse($result->hasOrphans);
    }
}
