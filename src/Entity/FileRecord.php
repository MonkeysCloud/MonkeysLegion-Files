<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Entity;

use DateTimeImmutable;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Tracked file entity with PHP 8.4 property hooks and asymmetric
 * visibility. Replaces all getter/setter methods with native hooks.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
class FileRecord
{
    // ── Identity ─────────────────────────────────────────────────

    public private(set) ?int $id = null;

    public private(set) string $uuid;

    // ── Storage Fields ───────────────────────────────────────────

    public string $disk;

    /** Normalized path — set hook strips leading slashes. */
    public string $path {
        set(string $value) {
            $this->path = ltrim($value, '/');
        }
    }

    public string $originalName;
    public string $mimeType;
    public int $size;
    public Visibility $visibility = Visibility::Private;

    // ── Checksums ────────────────────────────────────────────────

    public ?string $checksumSha256 = null;
    public ?string $checksumMd5 = null;

    // ── Polymorphic Attachment ─────────────────────────────────────

    public ?string $fileableType = null;
    public ?int $fileableId = null;
    public ?string $collection = null;

    /** @var array<string, mixed> */
    public array $metadata = [];

    // ── Tracking ─────────────────────────────────────────────────

    public int $accessCount = 0;
    public ?DateTimeImmutable $lastAccessedAt = null;
    public private(set) DateTimeImmutable $createdAt;
    public private(set) ?DateTimeImmutable $updatedAt = null;
    public ?DateTimeImmutable $deletedAt = null;

    // ── Computed Properties (get hooks) ──────────────────────────

    /** File extension derived from original name. */
    public string $extension {
        get => pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    /** Basename without extension. */
    public string $basename {
        get => pathinfo($this->originalName, PATHINFO_FILENAME);
    }

    /** Whether the file is an image based on MIME type. */
    public bool $isImage {
        get => str_starts_with($this->mimeType, 'image/');
    }

    /** Whether the file is a video based on MIME type. */
    public bool $isVideo {
        get => str_starts_with($this->mimeType, 'video/');
    }

    /** Whether the file is an audio file. */
    public bool $isAudio {
        get => str_starts_with($this->mimeType, 'audio/');
    }

    /** Whether the file is a document (PDF, Word, spreadsheet, etc). */
    public bool $isDocument {
        get => in_array($this->mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'text/markdown',
        ], true);
    }

    /** Directory portion of the path. */
    public string $directory {
        get => dirname($this->path) !== '.' ? dirname($this->path) : '';
    }

    /** Whether the file has been soft-deleted. */
    public bool $isDeleted {
        get => $this->deletedAt !== null;
    }

    /** Human-readable file size. */
    public string $humanSize {
        get => match (true) {
            $this->size >= 1_073_741_824 => round($this->size / 1_073_741_824, 2) . ' GB',
            $this->size >= 1_048_576     => round($this->size / 1_048_576, 2) . ' MB',
            $this->size >= 1_024         => round($this->size / 1_024, 2) . ' KB',
            default                      => $this->size . ' B',
        };
    }

    // ── Constructor ──────────────────────────────────────────────

    public function __construct(
        string $disk,
        string $path,
        string $originalName,
        string $mimeType,
        int $size,
        ?string $uuid = null,
    ) {
        $this->disk         = $disk;
        $this->path         = $path; // triggers set hook
        $this->originalName = $originalName;
        $this->mimeType     = $mimeType;
        $this->size         = $size;
        $this->uuid         = $uuid ?? self::generateUuid();
        $this->createdAt    = new DateTimeImmutable();
    }

    // ── Business Logic ───────────────────────────────────────────

    /** Record a file access. */
    public function recordAccess(): void
    {
        $this->accessCount++;
        $this->lastAccessedAt = new DateTimeImmutable();
    }

    /** Set checksum for a given algorithm. */
    public function setChecksum(string $hash, string $algo = 'sha256'): void
    {
        match ($algo) {
            'md5'   => $this->checksumMd5 = $hash,
            default => $this->checksumSha256 = $hash,
        };
        $this->touch();
    }

    /** Attach to a polymorphic parent. */
    public function attachTo(string $type, int $id, ?string $collection = null): void
    {
        $this->fileableType = $type;
        $this->fileableId   = $id;
        $this->collection   = $collection;
        $this->touch();
    }

    /** Soft-delete this record. */
    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
    }

    /** Restore from soft-delete. */
    public function restore(): void
    {
        $this->deletedAt = null;
        $this->touch();
    }

    // ── Serialization ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'disk'             => $this->disk,
            'path'             => $this->path,
            'original_name'    => $this->originalName,
            'mime_type'        => $this->mimeType,
            'size'             => $this->size,
            'visibility'       => $this->visibility->value,
            'checksum_sha256'  => $this->checksumSha256,
            'checksum_md5'     => $this->checksumMd5,
            'metadata'         => json_encode($this->metadata, JSON_THROW_ON_ERROR),
            'fileable_type'    => $this->fileableType,
            'fileable_id'      => $this->fileableId,
            'collection'       => $this->collection,
            'access_count'     => $this->accessCount,
            'last_accessed_at' => $this->lastAccessedAt?->format('Y-m-d H:i:s'),
            'created_at'       => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'       => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at'       => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $record = new self(
            disk: $data['disk'],
            path: $data['path'],
            originalName: $data['original_name'],
            mimeType: $data['mime_type'],
            size: (int) $data['size'],
            uuid: $data['uuid'] ?? null,
        );

        if (isset($data['id'])) {
            $record->id = (int) $data['id'];
        }

        $record->visibility       = Visibility::tryFrom($data['visibility'] ?? 'private') ?? Visibility::Private;
        $record->checksumSha256   = $data['checksum_sha256'] ?? null;
        $record->checksumMd5      = $data['checksum_md5'] ?? null;
        $record->fileableType     = $data['fileable_type'] ?? null;
        $record->fileableId       = isset($data['fileable_id']) ? (int) $data['fileable_id'] : null;
        $record->collection       = $data['collection'] ?? null;
        $record->accessCount      = (int) ($data['access_count'] ?? 0);
        $record->lastAccessedAt   = isset($data['last_accessed_at'])
            ? new DateTimeImmutable($data['last_accessed_at']) : null;
        $record->createdAt        = isset($data['created_at'])
            ? new DateTimeImmutable($data['created_at']) : new DateTimeImmutable();
        $record->updatedAt        = isset($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at']) : null;
        $record->deletedAt        = isset($data['deleted_at'])
            ? new DateTimeImmutable($data['deleted_at']) : null;

        if (isset($data['metadata'])) {
            $record->metadata = is_string($data['metadata'])
                ? (json_decode($data['metadata'], true) ?? [])
                : $data['metadata'];
        }

        return $record;
    }

    // ── Internal ─────────────────────────────────────────────────

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
