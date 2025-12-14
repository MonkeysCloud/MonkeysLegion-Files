<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Upload;

use Psr\SimpleCache\CacheInterface;
use MonkeysLegion\Files\Contracts\ChunkedUploadInterface;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\ChunkedUploadException;

/**
 * Chunked upload manager using MonkeysLegion-Cache.
 * 
 * Handles multipart file uploads with:
 * - Resume capability
 * - Chunk verification
 * - Automatic cleanup
 * - Progress tracking
 */
final class ChunkedUploadManager implements ChunkedUploadInterface
{
    private const CACHE_PREFIX = 'ml_files_chunked:';

    public function __construct(
        public StorageInterface $storage,
        public string $tempDir,
        public \Psr\SimpleCache\CacheInterface $cache,
        public int $chunkSize = 5242880, // 5MB default
        public int $uploadExpiry = 86400, // 24 hours
    ) {
        $this->ensureTempDir();
    }

    /**
     * {@inheritdoc}
     */
    public function initiate(string $filename, int $totalSize, string $mimeType, array $metadata = []): string
    {
        $uploadId = bin2hex(random_bytes(32));
        $chunkCount = (int) ceil($totalSize / $this->chunkSize);

        $uploadData = [
            'id' => $uploadId,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'total_size' => $totalSize,
            'chunk_count' => $chunkCount,
            'chunk_size' => $this->chunkSize,
            'uploaded_chunks' => [],
            'metadata' => $metadata,
            'created_at' => time(),
            'expires_at' => time() + $this->uploadExpiry,
        ];

        $this->saveUploadState($uploadId, $uploadData);
        $this->ensureUploadDir($uploadId);

        return $uploadId;
    }

    /**
     * {@inheritdoc}
     */
    public function uploadChunk(string $uploadId, int $chunkIndex, $data, int $chunkSize): bool
    {
        $state = $this->getUploadState($uploadId);

        if (!$state) {
            throw new ChunkedUploadException("Upload session not found: {$uploadId}");
        }

        if (time() > $state['expires_at']) {
            $this->abort($uploadId);
            throw new ChunkedUploadException("Upload session expired");
        }

        if ($chunkIndex >= $state['chunk_count']) {
            throw new ChunkedUploadException("Invalid chunk index: {$chunkIndex}");
        }

        // Check if chunk already uploaded (idempotent)
        if (isset($state['uploaded_chunks'][$chunkIndex])) {
            return true;
        }

        $chunkPath = $this->getChunkPath($uploadId, $chunkIndex);

        // Handle both string and stream data
        if (is_resource($data)) {
            $written = file_put_contents($chunkPath, stream_get_contents($data));
        } else {
            $written = file_put_contents($chunkPath, $data);
        }

        if ($written === false) {
            throw new ChunkedUploadException("Failed to write chunk");
        }

        if ($written !== $chunkSize) {
            @unlink($chunkPath);
            throw new ChunkedUploadException(
                "Chunk size mismatch: expected {$chunkSize}, got {$written}"
            );
        }

        // Verify chunk integrity
        $actualSize = filesize($chunkPath);
        if ($actualSize !== $chunkSize) {
            @unlink($chunkPath);
            throw new ChunkedUploadException(
                "Chunk file size mismatch: expected {$chunkSize}, got {$actualSize}"
            );
        }

        // Calculate checksum for verification
        $checksum = hash_file('sha256', $chunkPath);

        $state['uploaded_chunks'][$chunkIndex] = [
            'index' => $chunkIndex,
            'size' => $chunkSize,
            'checksum' => $checksum,
            'uploaded_at' => time(),
        ];

        $this->saveUploadState($uploadId, $state);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function complete(string $uploadId): string
    {
        $state = $this->getUploadState($uploadId);

        if (!$state) {
            throw new ChunkedUploadException("Upload session not found: {$uploadId}");
        }

        // Verify all chunks are uploaded
        $uploadedCount = count($state['uploaded_chunks']);
        if ($uploadedCount !== $state['chunk_count']) {
            throw new ChunkedUploadException(
                "Incomplete upload: {$uploadedCount}/{$state['chunk_count']} chunks uploaded"
            );
        }

        // Assemble the file
        $finalPath = $this->assembleFile($uploadId, $state);

        // Verify final file size
        $finalSize = filesize($finalPath);
        if ($finalSize !== $state['total_size']) {
            @unlink($finalPath);
            throw new ChunkedUploadException(
                "Final file size mismatch: expected {$state['total_size']}, got {$finalSize}"
            );
        }

        // Generate storage path
        $extension = pathinfo($state['filename'], PATHINFO_EXTENSION) ?: '';
        $storagePath = $this->generateStoragePath($extension);

        // Store in final location
        $stream = fopen($finalPath, 'rb');
        if ($stream === false) {
            throw new ChunkedUploadException("Failed to open assembled file");
        }

        try {
            $this->storage->putStream($storagePath, $stream);
        } finally {
            fclose($stream);
        }

        // Cleanup
        $this->cleanup($uploadId);
        $this->deleteUploadState($uploadId);

        return $storagePath;
    }

    /**
     * {@inheritdoc}
     */
    public function abort(string $uploadId): bool
    {
        $this->cleanup($uploadId);
        $this->deleteUploadState($uploadId);
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getProgress(string $uploadId): array
    {
        $state = $this->getUploadState($uploadId);

        if (!$state) {
            return [
                'found' => false,
                'error' => 'Upload session not found',
            ];
        }

        $uploadedChunks = count($state['uploaded_chunks']);
        $uploadedBytes = array_sum(array_column($state['uploaded_chunks'], 'size'));
        $percent = $state['chunk_count'] > 0 
            ? round(($uploadedChunks / $state['chunk_count']) * 100, 2) 
            : 0;

        return [
            'found' => true,
            'upload_id' => $uploadId,
            'filename' => $state['filename'],
            'mime_type' => $state['mime_type'],
            'total_size' => $state['total_size'],
            'chunk_count' => $state['chunk_count'],
            'chunk_size' => $state['chunk_size'],
            'uploaded_chunks' => $uploadedChunks,
            'uploaded_bytes' => $uploadedBytes,
            'percent' => $percent,
            'missing_chunks' => $this->getMissingChunks($state),
            'created_at' => $state['created_at'],
            'expires_at' => $state['expires_at'],
            'is_expired' => time() > $state['expires_at'],
            'is_complete' => $uploadedChunks === $state['chunk_count'],
        ];
    }

    /**
     * Get list of missing chunk indices.
     */
    private function getMissingChunks(array $state): array
    {
        $missing = [];
        $uploadedIndices = array_keys($state['uploaded_chunks']);
        
        for ($i = 0; $i < $state['chunk_count']; $i++) {
            if (!in_array($i, $uploadedIndices, true)) {
                $missing[] = $i;
            }
        }
        
        return $missing;
    }

    /**
     * Assemble chunks into final file.
     */
    private function assembleFile(string $uploadId, array $state): string
    {
        $finalPath = $this->getUploadDir($uploadId) . '/assembled';
        $output = fopen($finalPath, 'wb');

        if ($output === false) {
            throw new ChunkedUploadException("Failed to create output file");
        }

        try {
            // Sort chunks by index
            ksort($state['uploaded_chunks']);

            foreach ($state['uploaded_chunks'] as $index => $chunkInfo) {
                $chunkPath = $this->getChunkPath($uploadId, $index);
                
                if (!file_exists($chunkPath)) {
                    throw new ChunkedUploadException("Missing chunk file: {$index}");
                }

                $chunk = fopen($chunkPath, 'rb');
                if ($chunk === false) {
                    throw new ChunkedUploadException("Failed to open chunk: {$index}");
                }

                try {
                    stream_copy_to_stream($chunk, $output);
                } finally {
                    fclose($chunk);
                }
            }
        } finally {
            fclose($output);
        }

        return $finalPath;
    }

    /**
     * Generate a storage path for the final file.
     */
    private function generateStoragePath(string $extension): string
    {
        $filename = bin2hex(random_bytes(16));
        
        if ($extension) {
            $filename .= '.' . ltrim($extension, '.');
        }

        $date = date('Y/m/d');
        
        return "uploads/{$date}/{$filename}";
    }

    /**
     * Cleanup upload directory and chunks.
     */
    private function cleanup(string $uploadId): void
    {
        $uploadDir = $this->getUploadDir($uploadId);
        
        if (is_dir($uploadDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }

            @rmdir($uploadDir);
        }
    }

    /**
     * Get upload state from cache.
     */
    private function getUploadState(string $uploadId): ?array
    {
        $key = self::CACHE_PREFIX . $uploadId;
        $state = $this->getStore()->get($key);
        
        return is_array($state) ? $state : null;
    }

    /**
     * Save upload state to cache.
     */
    private function saveUploadState(string $uploadId, array $state): void
    {
        $key = self::CACHE_PREFIX . $uploadId;
        $ttl = max(0, $state['expires_at'] - time());
        
        $this->getStore()->set($key, $state, $ttl > 0 ? $ttl : $this->uploadExpiry);
    }

    /**
     * Delete upload state from cache.
     */
    private function deleteUploadState(string $uploadId): void
    {
        $key = self::CACHE_PREFIX . $uploadId;
        $this->getStore()->delete($key);
    }

    /**
     * Get the cache store to use.
     */
    private function getStore(): \Psr\SimpleCache\CacheInterface
    {
        return $this->cache;
    }

    /**
     * Ensure the temp directory exists.
     */
    private function ensureTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    /**
     * Ensure the upload directory exists.
     */
    private function ensureUploadDir(string $uploadId): void
    {
        $dir = $this->getUploadDir($uploadId);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Get the upload directory path.
     */
    private function getUploadDir(string $uploadId): string
    {
        return $this->tempDir . '/' . $uploadId;
    }

    /**
     * Get the chunk file path.
     */
    private function getChunkPath(string $uploadId, int $chunkIndex): string
    {
        return $this->getUploadDir($uploadId) . "/chunk_{$chunkIndex}";
    }

    /**
     * Cleanup expired uploads.
     */
    public function cleanupExpired(): int
    {
        $count = 0;
        
        if (!is_dir($this->tempDir)) {
            return $count;
        }

        $dirs = new \DirectoryIterator($this->tempDir);
        
        foreach ($dirs as $dir) {
            if ($dir->isDot() || !$dir->isDir()) {
                continue;
            }

            $uploadId = $dir->getFilename();
            $state = $this->getUploadState($uploadId);
            
            // If no state or expired, cleanup
            if (!$state || time() > ($state['expires_at'] ?? 0)) {
                $this->cleanup($uploadId);
                $this->deleteUploadState($uploadId);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the chunk size.
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Get the upload expiry time in seconds.
     */
    public function getUploadExpiry(): int
    {
        return $this->uploadExpiry;
    }
}
