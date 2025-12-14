<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

/**
 * Interface for handling chunked/multipart file uploads.
 * Essential for large file uploads on production sites.
 */
interface ChunkedUploadInterface
{
    /**
     * Initialize a chunked upload session.
     *
     * @param string $filename Original filename
     * @param int $totalSize Total file size in bytes
     * @param string $mimeType MIME type of the file
     * @param array $metadata Additional metadata to store
     * @return string Unique upload session ID
     */
    public function initiate(string $filename, int $totalSize, string $mimeType, array $metadata = []): string;

    /**
     * Upload a single chunk.
     *
     * @param string $uploadId Upload session ID
     * @param int $chunkIndex Zero-based chunk index
     * @param resource|string $data Chunk data (stream or string)
     * @param int $chunkSize Size of the chunk in bytes
     * @return bool Success status
     */
    public function uploadChunk(string $uploadId, int $chunkIndex, $data, int $chunkSize): bool;

    /**
     * Complete the upload by assembling all chunks.
     *
     * @param string $uploadId Upload session ID
     * @return string Final stored file path
     */
    public function complete(string $uploadId): string;

    /**
     * Abort and cleanup an incomplete upload.
     *
     * @param string $uploadId Upload session ID
     * @return bool Success status
     */
    public function abort(string $uploadId): bool;

    /**
     * Get current upload progress.
     *
     * @param string $uploadId Upload session ID
     * @return array Progress data including percentage, uploaded chunks, etc.
     */
    public function getProgress(string $uploadId): array;
}
