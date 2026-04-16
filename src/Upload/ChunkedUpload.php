<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Upload;

use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\UploadException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Handles chunked/resumable file uploads. Each chunk is stored
 * temporarily and assembled when all parts arrive.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ChunkedUpload
{
    /** @var array<int, string> chunk index => temp path */
    private array $chunks = [];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $uploadId,
        private readonly int $totalChunks,
        private readonly string $tmpDirectory = '_chunks',
    ) {
        if ($this->totalChunks < 1) {
            throw new UploadException('Total chunks must be at least 1');
        }
    }

    /** Unique upload session ID. */
    public string $id {
        get => $this->uploadId;
    }

    /** Number of chunks received so far. */
    public int $receivedChunks {
        get => count($this->chunks);
    }

    /** Whether all chunks have been received. */
    public bool $isComplete {
        get => $this->receivedChunks === $this->totalChunks;
    }

    /** Progress as a percentage (0–100). */
    public float $progress {
        get => round(($this->receivedChunks / $this->totalChunks) * 100, 1);
    }

    /**
     * Add a chunk.
     *
     * @param int    $index    0-based chunk index
     * @param string $contents Chunk binary data
     */
    public function addChunk(int $index, string $contents): void
    {
        if ($index < 0 || $index >= $this->totalChunks) {
            throw new UploadException(
                "Chunk index {$index} out of range [0, {$this->totalChunks})",
            );
        }

        $chunkPath = "{$this->tmpDirectory}/{$this->uploadId}/chunk_{$index}";
        $this->storage->put($chunkPath, $contents);
        $this->chunks[$index] = $chunkPath;
    }

    /**
     * Assemble all chunks into a single file at the target path.
     *
     * @param string $targetPath Final file path in storage
     *
     * @throws UploadException If not all chunks received
     */
    public function assemble(string $targetPath): bool
    {
        if (!$this->isComplete) {
            throw new UploadException(
                "Cannot assemble: received {$this->receivedChunks}/{$this->totalChunks} chunks",
            );
        }

        // Concatenate in order
        $assembled = '';

        for ($i = 0; $i < $this->totalChunks; $i++) {
            if (!isset($this->chunks[$i])) {
                throw new UploadException("Missing chunk {$i}");
            }

            $data = $this->storage->get($this->chunks[$i]);

            if ($data === null) {
                throw new UploadException("Chunk {$i} data not found in storage");
            }

            $assembled .= $data;
        }

        // Store the assembled file
        $result = $this->storage->put($targetPath, $assembled);

        // Cleanup chunks
        $this->cleanup();

        return $result;
    }

    /**
     * Remove all temporary chunk data.
     */
    public function cleanup(): void
    {
        $this->storage->deleteDirectory("{$this->tmpDirectory}/{$this->uploadId}");
        $this->chunks = [];
    }

    /**
     * Get the paths of missing chunks.
     *
     * @return list<int>
     */
    public function missingChunks(): array
    {
        $missing = [];

        for ($i = 0; $i < $this->totalChunks; $i++) {
            if (!isset($this->chunks[$i])) {
                $missing[] = $i;
            }
        }

        return $missing;
    }
}
