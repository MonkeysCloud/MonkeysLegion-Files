<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Entity;

use DateTimeImmutable;
use Random\RandomException;

/**
 * Represents a tracked file in the database.
 */
class FileRecord
{
    public function __construct(
        private string $disk,
        private string $path,
        private string $originalName,
        private string $mimeType,
        private int $size,
        private ?int $id = null,
        private ?string $uuid = null,
        private ?string $checksumMd5 = null,
        private ?string $checksumSha256 = null,
        private string $visibility = 'private',
        private array $metadata = [],
        private ?string $fileableType = null,
        private ?int $fileableId = null,
        private ?string $collection = null,
        private int $accessCount = 0,
        private ?DateTimeImmutable $lastAccessedAt = null,
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null,
        private ?DateTimeImmutable $deletedAt = null,
    ) {
        $this->uuid = $uuid ?? self::generateUuid();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    private static function generateUuid(): string
    {
        try {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (RandomException $e) {
            throw new \RuntimeException('Failed to generate UUID', 0, $e);
        }
    }

    // Getters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getDisk(): string
    {
        return $this->disk;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function getChecksumMd5(): ?string
    {
        return $this->checksumMd5;
    }

    public function getChecksumSha256(): ?string
    {
        return $this->checksumSha256;
    }
    
    public function getChecksum(string $algo = 'sha256'): ?string
    {
        return $algo === 'md5' ? $this->checksumMd5 : $this->checksumSha256;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function getFileableType(): ?string
    {
        return $this->fileableType;
    }

    public function getFileableId(): ?int
    {
        return $this->fileableId;
    }

    public function getCollection(): ?string
    {
        return $this->collection;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function getLastAccessedAt(): ?DateTimeImmutable
    {
        return $this->lastAccessedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
    
    public function getExtension(): string
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    public function getBasename(): string
    {
        return pathinfo($this->originalName, PATHINFO_FILENAME);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
    
    // Setters

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;
        $this->touch();
        return $this;
    }

    public function setChecksum(string $checksum, string $algorithm = 'sha256'): self
    {
        if ($algorithm === 'md5') {
            $this->checksumMd5 = $checksum;
        } else {
            $this->checksumSha256 = $checksum;
        }
        $this->touch();
        return $this;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        $this->touch();
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        $this->touch();
        return $this;
    }
    
    public function setFileable(?string $type, ?int $id): self
    {
        $this->fileableType = $type;
        $this->fileableId = $id;
        $this->touch();
        return $this;
    }

    public function setCollection(?string $collection): self
    {
        $this->collection = $collection;
        $this->touch();
        return $this;
    }
    
    public function recordAccess(): self
    {
        $this->accessCount++;
        $this->lastAccessedAt = new DateTimeImmutable();
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new DateTimeImmutable();
        return $this;
    }

    public function restore(): self
    {
        $this->deletedAt = null;
        $this->touch();
        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            disk: $data['disk'],
            path: $data['path'],
            originalName: $data['original_name'],
            mimeType: $data['mime_type'],
            size: (int) $data['size'],
            id: isset($data['id']) ? (int) $data['id'] : null,
            uuid: $data['uuid'] ?? null,
            checksumMd5: $data['checksum_md5'] ?? $data['checksum'] ?? null,
            checksumSha256: $data['checksum_sha256'] ?? null,
            metadata: is_string($data['metadata'] ?? []) ? json_decode($data['metadata'], true) : ($data['metadata'] ?? []),
            fileableType: $data['fileable_type'] ?? null,
            fileableId: isset($data['fileable_id']) ? (int) $data['fileable_id'] : null,
            collection: $data['collection'] ?? null,
            accessCount: (int) ($data['access_count'] ?? 0),
            lastAccessedAt: isset($data['last_accessed_at']) ? new DateTimeImmutable($data['last_accessed_at']) : null,
            createdAt: isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null,
        );
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'disk' => $this->disk,
            'path' => $this->path,
            'original_name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'checksum_md5' => $this->checksumMd5,
            'checksum_sha256' => $this->checksumSha256,
            'metadata' => json_encode($this->metadata),
            'fileable_type' => $this->fileableType,
            'fileable_id' => $this->fileableId,
            'collection' => $this->collection,
            'access_count' => $this->accessCount,
            'last_accessed_at' => $this->lastAccessedAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at' => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
