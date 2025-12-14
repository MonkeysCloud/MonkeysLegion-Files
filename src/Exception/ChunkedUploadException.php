<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Exception;

/**
 * Chunked upload specific errors.
 */
class ChunkedUploadException extends UploadException
{
    public function __construct(
        string $message,
        public readonly ?string $uploadId = null,
        public readonly ?int $chunkIndex = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            previous: $previous,
            context: array_filter([
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
            ])
        );
    }
}
