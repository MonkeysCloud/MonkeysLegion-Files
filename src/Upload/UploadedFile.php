<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Upload;

use MonkeysLegion\Files\Exception\UploadException;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Value object wrapping a PHP uploaded file ($_FILES entry).
 * Provides safe access with property hooks for computed properties.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class UploadedFile
{
    /** File extension derived from client name. */
    public string $extension {
        get => strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /** Whether the file appears to be an image by MIME. */
    public bool $isImage {
        get => str_starts_with($this->mimeType, 'image/');
    }

    /** Whether the file appears to be a video by MIME. */
    public bool $isVideo {
        get => str_starts_with($this->mimeType, 'video/');
    }

    /** Whether the file appears to be audio by MIME. */
    public bool $isAudio {
        get => str_starts_with($this->mimeType, 'audio/');
    }

    /** Basename without extension. */
    public string $basename {
        get => pathinfo($this->clientName, PATHINFO_FILENAME);
    }

    /** Human-readable size. */
    public string $humanSize {
        get => match (true) {
            $this->size >= 1_073_741_824 => round($this->size / 1_073_741_824, 2) . ' GB',
            $this->size >= 1_048_576     => round($this->size / 1_048_576, 2) . ' MB',
            $this->size >= 1_024         => round($this->size / 1_024, 2) . ' KB',
            default                      => $this->size . ' B',
        };
    }

    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientName,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new UploadException(
                "Upload error for '{$this->clientName}': " . self::errorMessage($this->error),
            );
        }
    }

    /**
     * Create from a $_FILES entry.
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, error: int} $file
     */
    public static function fromGlobal(array $file): self
    {
        return new self(
            tmpPath: $file['tmp_name'],
            clientName: $file['name'],
            mimeType: $file['type'],
            size: $file['size'],
            error: $file['error'],
        );
    }

    /** Get a readable stream for the uploaded file. */
    public function getStream(): mixed
    {
        $stream = fopen($this->tmpPath, 'rb');

        if ($stream === false) {
            throw new UploadException("Cannot open uploaded file: {$this->clientName}");
        }

        return $stream;
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension',
            default               => "Unknown error ({$code})",
        };
    }
}
