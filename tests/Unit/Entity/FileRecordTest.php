<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Tests\Unit\Entity;

use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Files\Entity\FileRecord
 */
final class FileRecordTest extends TestCase
{
    private function make(string $name = 'photo.jpg', string $mime = 'image/jpeg', int $size = 1024): FileRecord
    {
        return new FileRecord(
            disk: 'local',
            path: '/uploads/photo.jpg',
            originalName: $name,
            mimeType: $mime,
            size: $size,
        );
    }

    // ── Constructor ──────────────────────────────────────────────

    public function testConstructSetsFields(): void
    {
        $r = $this->make();

        $this->assertSame('local', $r->disk);
        $this->assertSame('image/jpeg', $r->mimeType);
        $this->assertSame(1024, $r->size);
        $this->assertNull($r->id);
    }

    public function testUuidGenerated(): void
    {
        $r = $this->make();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $r->uuid,
        );
    }

    // ── Set Hook: path ───────────────────────────────────────────

    public function testPathSetHookStripsLeadingSlash(): void
    {
        $r = $this->make();
        $this->assertSame('uploads/photo.jpg', $r->path);
    }

    // ── Get Hooks ────────────────────────────────────────────────

    public function testExtensionHook(): void
    {
        $this->assertSame('jpg', $this->make('photo.jpg')->extension);
        $this->assertSame('png', $this->make('image.png')->extension);
    }

    public function testBasenameHook(): void
    {
        $this->assertSame('photo', $this->make('photo.jpg')->basename);
    }

    public function testIsImageHook(): void
    {
        $this->assertTrue($this->make('a.jpg', 'image/jpeg')->isImage);
        $this->assertFalse($this->make('a.pdf', 'application/pdf')->isImage);
    }

    public function testIsVideoHook(): void
    {
        $this->assertTrue($this->make('v.mp4', 'video/mp4')->isVideo);
        $this->assertFalse($this->make('a.jpg', 'image/jpeg')->isVideo);
    }

    public function testIsDeletedHook(): void
    {
        $r = $this->make();
        $this->assertFalse($r->isDeleted);

        $r->softDelete();
        $this->assertTrue($r->isDeleted);
    }

    public function testHumanSizeHook(): void
    {
        $this->assertSame('1 KB', $this->make(size: 1024)->humanSize);
        $this->assertSame('1.5 MB', $this->make(size: 1_572_864)->humanSize);
        $this->assertSame('500 B', $this->make(size: 500)->humanSize);
    }

    // ── Business Logic ───────────────────────────────────────────

    public function testRecordAccess(): void
    {
        $r = $this->make();
        $this->assertSame(0, $r->accessCount);

        $r->recordAccess();
        $this->assertSame(1, $r->accessCount);
        $this->assertNotNull($r->lastAccessedAt);
    }

    public function testSetChecksum(): void
    {
        $r = $this->make();
        $r->setChecksum('abc123');
        $this->assertSame('abc123', $r->checksumSha256);

        $r->setChecksum('def456', 'md5');
        $this->assertSame('def456', $r->checksumMd5);
    }

    public function testAttachTo(): void
    {
        $r = $this->make();
        $r->attachTo('App\\Entity\\User', 42, 'avatars');

        $this->assertSame('App\\Entity\\User', $r->fileableType);
        $this->assertSame(42, $r->fileableId);
        $this->assertSame('avatars', $r->collection);
    }

    public function testSoftDeleteAndRestore(): void
    {
        $r = $this->make();
        $r->softDelete();
        $this->assertTrue($r->isDeleted);

        $r->restore();
        $this->assertFalse($r->isDeleted);
    }

    // ── Serialization ────────────────────────────────────────────

    public function testToArray(): void
    {
        $r = $this->make();
        $arr = $r->toArray();

        $this->assertSame('local', $arr['disk']);
        $this->assertSame('uploads/photo.jpg', $arr['path']);
        $this->assertSame('image/jpeg', $arr['mime_type']);
        $this->assertSame(1024, $arr['size']);
        $this->assertSame('private', $arr['visibility']);
    }

    public function testFromArray(): void
    {
        $data = [
            'id'            => 99,
            'uuid'          => '550e8400-e29b-41d4-a716-446655440000',
            'disk'          => 's3',
            'path'          => 'docs/readme.md',
            'original_name' => 'readme.md',
            'mime_type'     => 'text/markdown',
            'size'          => 2048,
            'visibility'    => 'public',
        ];

        $r = FileRecord::fromArray($data);

        $this->assertSame(99, $r->id);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $r->uuid);
        $this->assertSame('s3', $r->disk);
        $this->assertSame(Visibility::Public, $r->visibility);
    }

    public function testRoundTrip(): void
    {
        $original = $this->make();
        $original->setChecksum('abc123');
        $original->attachTo('User', 1);

        $restored = FileRecord::fromArray($original->toArray());

        $this->assertSame($original->uuid, $restored->uuid);
        $this->assertSame($original->disk, $restored->disk);
        $this->assertSame($original->path, $restored->path);
        $this->assertSame($original->checksumSha256, $restored->checksumSha256);
        $this->assertSame($original->fileableType, $restored->fileableType);
    }
}
