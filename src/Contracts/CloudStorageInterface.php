<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Extended interface for cloud storage drivers that support
 * signed/temporary URLs and presigned upload URLs.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface CloudStorageInterface extends StorageInterface
{
    /**
     * Generate a temporary/signed URL for the file.
     *
     * @param string               $path       Relative path within the storage
     * @param \DateTimeInterface   $expiration URL expiration time
     * @param array<string, mixed> $options    Driver-specific options
     */
    public function temporaryUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string;

    /**
     * Generate a presigned URL for direct browser-to-storage uploads.
     *
     * This bypasses the application server entirely — the client uploads
     * directly to S3/GCS. **New vs Laravel/Symfony.**
     *
     * @param string             $path       Target path in storage
     * @param \DateTimeInterface $expiration URL expiration time
     * @param array<string, mixed> $options  Driver-specific options (content-type, max-size, etc.)
     */
    public function presignedUploadUrl(
        string $path,
        \DateTimeInterface $expiration,
        array $options = [],
    ): string;
}
