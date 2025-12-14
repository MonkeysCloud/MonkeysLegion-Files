<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Repository;

use MonkeysLegion\Files\Entity\FileRecord;
use PDO;

/**
 * Repository for file records using MonkeysLegion-Database.
 * 
 * Provides database tracking for uploaded files including:
 * - File metadata storage
 * - Soft delete support
 * - Access tracking
 * - Polymorphic relationships
 */
final class FileRepository
{
    private PDO $pdo;

    /**
     * @param object $connection MonkeysLegion Database connection (has pdo() method)
     * @param string $tableName Main files table name
     * @param string $conversionsTable Conversions table name
     * @param bool $trackAccess Track file access counts
     * @param bool $softDelete Use soft deletes
     */
    public function __construct(
        private object $connection,
        private string $tableName = 'ml_files',
        private string $conversionsTable = 'ml_file_conversions',
        private bool $trackAccess = true,
        private bool $softDelete = true,
    ) {
        // Get PDO from MonkeysLegion Database connection
        $this->pdo = $this->connection->pdo();
    }

    /**
     * Create a new file record.
     */
    public function create(array $data): FileRecord
    {
        $id = $data['id'] ?? $this->generateUuid();
        
        $sql = "INSERT INTO {$this->tableName} (
            id, disk, path, original_name, mime_type, extension, size,
            checksum_md5, checksum_sha256, visibility, fileable_type, 
            fileable_id, collection, metadata, created_at, updated_at
        ) VALUES (
            :id, :disk, :path, :original_name, :mime_type, :extension, :size,
            :checksum_md5, :checksum_sha256, :visibility, :fileable_type,
            :fileable_id, :collection, :metadata, :created_at, :updated_at
        )";

        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'disk' => $data['disk'] ?? 'local',
            'path' => $data['path'],
            'original_name' => $data['original_name'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'extension' => $data['extension'] ?? null,
            'size' => $data['size'] ?? 0,
            'checksum_md5' => $data['checksum_md5'] ?? null,
            'checksum_sha256' => $data['checksum_sha256'] ?? null,
            'visibility' => $data['visibility'] ?? 'private',
            'fileable_type' => $data['fileable_type'] ?? null,
            'fileable_id' => $data['fileable_id'] ?? null,
            'collection' => $data['collection'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id);
    }

    /**
     * Find a file by ID.
     */
    public function find(string $id): ?FileRecord
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE id = :id";
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Find a file by UUID.
     */
    public function findByUuid(string $uuid): ?FileRecord
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE uuid = :uuid";
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uuid' => $uuid]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Find a file by path.
     */
    public function findByPath(string $path, ?string $disk = null): ?FileRecord
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE path = :path";
        $params = ['path' => $path];

        if ($disk !== null) {
            $sql .= " AND disk = :disk";
            $params['disk'] = $disk;
        }

        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Find files by checksum.
     */
    public function findByChecksum(string $checksum, string $algorithm = 'sha256'): array
    {
        $column = $algorithm === 'md5' ? 'checksum_md5' : 'checksum_sha256';
        
        $sql = "SELECT * FROM {$this->tableName} WHERE {$column} = :checksum";
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['checksum' => $checksum]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Find files by owner (polymorphic).
     */
    public function findByFileable(string $type, int $id, ?string $collection = null): array
    {
        return $this->findByOwner($type, $id, $collection);
    }

    public function findByOwner(string $type, int $id, ?string $collection = null): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE fileable_type = :type AND fileable_id = :id";
        $params = ['type' => $type, 'id' => $id];

        if ($collection !== null) {
            $sql .= " AND collection = :collection";
            $params['collection'] = $collection;
        }
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Find files by collection.
     */
    public function findByCollection(string $collection, ?string $ownerType = null, ?string $ownerId = null): array
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE collection = :collection";
        $params = ['collection' => $collection];

        if ($ownerType !== null && $ownerId !== null) {
            $sql .= " AND fileable_type = :type AND fileable_id = :id";
            $params['type'] = $ownerType;
            $params['id'] = $ownerId;
        }

        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Find files by MIME type pattern.
     */
    public function findByMimeType(string $pattern): array
    {
        $sql = "SELECT * FROM {$this->tableName} WHERE mime_type LIKE :pattern";
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pattern' => $pattern]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Update a file record.
     */
    public function update(string $id, array $data): bool
    {
        $allowedFields = [
            'path', 'original_name', 'mime_type', 'extension', 'size',
            'checksum_md5', 'checksum_sha256', 'visibility', 'fileable_type',
            'fileable_id', 'collection', 'metadata',
        ];

        $sets = [];
        $params = ['id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields, true)) {
                $sets[] = "{$key} = :{$key}";
                $params[$key] = $key === 'metadata' && is_array($value) 
                    ? json_encode($value) 
                    : $value;
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = "updated_at = :updated_at";
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $sets) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Save a file record (create or update).
     */
    public function save(FileRecord $file): bool
    {
        $data = $file->toArray();
        if ($file->getId()) {
            return $this->update((string)$file->getId(), $data);
        }
        
        $newFile = $this->create($data);
        $file->setId($newFile->getId());
        return true;
    }

    /**
     * Record file access.
     */
    public function recordAccess(string $id): void
    {
        if (!$this->trackAccess) {
            return;
        }

        $sql = "UPDATE {$this->tableName} 
                SET access_count = access_count + 1, 
                    last_accessed_at = :accessed_at,
                    updated_at = :updated_at 
                WHERE id = :id";

        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'accessed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Soft delete a file.
     */
    public function softDelete(string $id): bool
    {
        if (!$this->softDelete) {
            return $this->forceDelete($id);
        }

        $sql = "UPDATE {$this->tableName} 
                SET deleted_at = :deleted_at, updated_at = :updated_at 
                WHERE id = :id AND deleted_at IS NULL";

        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Restore a soft-deleted file.
     */
    public function restore(string $id): bool
    {
        $sql = "UPDATE {$this->tableName} 
                SET deleted_at = NULL, updated_at = :updated_at 
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Permanently delete a file record.
     */
    public function forceDelete(string $id): bool
    {
        // Delete conversions first
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->conversionsTable} WHERE file_id = :id"
        );
        $stmt->execute(['id' => $id]);

        // Delete the file record
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->tableName} WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Get files deleted before a certain date.
     */
    public function getDeletedBefore(\DateTimeInterface $date): array
    {
        if (!$this->softDelete) {
            return [];
        }

        $sql = "SELECT * FROM {$this->tableName} 
                WHERE deleted_at IS NOT NULL AND deleted_at < :date";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date' => $date->format('Y-m-d H:i:s')]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Get orphaned files (files with no owner).
     */
    public function getOrphanedFiles(): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE fileable_type IS NOT NULL 
                AND fileable_id IS NOT NULL";
        
        if ($this->softDelete) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    /**
     * Get total storage used by disk.
     */
    public function getTotalSizeByDisk(?string $disk = null): int
    {
        $sql = "SELECT COALESCE(SUM(size), 0) as total FROM {$this->tableName}";
        $params = [];

        if ($disk !== null) {
            $sql .= " WHERE disk = :disk";
            $params['disk'] = $disk;
        }

        if ($this->softDelete) {
            $sql .= ($disk !== null ? " AND" : " WHERE") . " deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get file count by disk.
     */
    public function getCountByDisk(?string $disk = null): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName}";
        $params = [];

        if ($disk !== null) {
            $sql .= " WHERE disk = :disk";
            $params['disk'] = $disk;
        }

        if ($this->softDelete) {
            $sql .= ($disk !== null ? " AND" : " WHERE") . " deleted_at IS NULL";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * Create a file conversion record.
     */
    public function createConversion(string $fileId, array $data): string
    {
        $id = $data['id'] ?? $this->generateUuid();
        
        $sql = "INSERT INTO {$this->conversionsTable} (
            id, file_id, conversion_name, disk, path, mime_type, 
            size, width, height, status, created_at
        ) VALUES (
            :id, :file_id, :conversion_name, :disk, :path, :mime_type,
            :size, :width, :height, :status, :created_at
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'file_id' => $fileId,
            'conversion_name' => $data['conversion_name'],
            'disk' => $data['disk'] ?? 'local',
            'path' => $data['path'],
            'mime_type' => $data['mime_type'] ?? null,
            'size' => $data['size'] ?? 0,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'status' => $data['status'] ?? 'completed',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * Get conversions for a file.
     */
    public function getConversions(string $fileId): array
    {
        $sql = "SELECT * FROM {$this->conversionsTable} WHERE file_id = :file_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['file_id' => $fileId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a specific conversion.
     */
    public function getConversion(string $fileId, string $conversionName): ?array
    {
        $sql = "SELECT * FROM {$this->conversionsTable} 
                WHERE file_id = :file_id AND conversion_name = :name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'file_id' => $fileId,
            'name' => $conversionName,
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }

    /**
     * Delete conversion records for a file.
     */
    public function deleteConversions(string $fileId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->conversionsTable} WHERE file_id = :file_id"
        );
        return $stmt->execute(['file_id' => $fileId]);
    }

    /**
     * Get the underlying PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get the MonkeysLegion Database connection.
     */
    public function getConnection(): object
    {
        return $this->connection;
    }

    /**
     * Hydrate a file record from database row.
     */
    private function hydrate(array $row): FileRecord
    {
        return new FileRecord(

            disk: $row['disk'],
            path: $row['path'],
            originalName: $row['original_name'],
            mimeType: $row['mime_type'],
            size: (int) $row['size'],
            uuid: $row['uuid'] ?? null,
            checksumMd5: $row['checksum_md5'],
            checksumSha256: $row['checksum_sha256'],
            visibility: $row['visibility'] ?? 'private',
            accessCount: (int) ($row['access_count'] ?? 0),
            lastAccessedAt: $row['last_accessed_at'] 
                ? new \DateTimeImmutable($row['last_accessed_at']) 
                : null,
            fileableType: $row['fileable_type'],
            fileableId: isset($row['fileable_id']) ? (int) $row['fileable_id'] : null,
            collection: $row['collection'],
            metadata: $row['metadata'] ? json_decode($row['metadata'], true) : [],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
            deletedAt: $row['deleted_at'] 
                ? new \DateTimeImmutable($row['deleted_at']) 
                : null,
        );
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
