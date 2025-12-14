<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

use Psr\Http\Message\StreamInterface;

/**
 * Contract for storage drivers.
 * 
 * All storage implementations (local, S3, etc.) must implement this interface
 * to ensure consistent behavior across different storage backends.
 */
interface StorageInterface
{
    /**
     * Store a file from string contents.
     *
     * @param string $path    Relative path within the storage
     * @param string $contents File contents
     * @param array  $options  Driver-specific options (visibility, metadata, etc.)
     * @return bool True on success
     */
    public function put(string $path, string $contents, array $options = []): bool;

    /**
     * Store a file from a stream.
     *
     * @param string          $path    Relative path within the storage
     * @param resource|StreamInterface $stream  Input stream
     * @param array           $options Driver-specific options
     * @return bool True on success
     */
    public function putStream(string $path, mixed $stream, array $options = []): bool;

    /**
     * Get file contents as string.
     *
     * @param string $path Relative path within the storage
     * @return string|null File contents or null if not found
     */
    public function get(string $path): ?string;

    /**
     * Get a readable stream for the file.
     *
     * @param string $path Relative path within the storage
     * @return resource|null Stream resource or null if not found
     */
    public function getStream(string $path): mixed;

    /**
     * Delete a file.
     *
     * @param string $path Relative path within the storage
     * @return bool True if deleted or didn't exist
     */
    public function delete(string $path): bool;

    /**
     * Delete multiple files.
     *
     * @param array<string> $paths Array of paths to delete
     * @return bool True if all deleted successfully
     */
    public function deleteMultiple(array $paths): bool;

    /**
     * Check if a file exists.
     *
     * @param string $path Relative path within the storage
     * @return bool True if file exists
     */
    public function exists(string $path): bool;

    /**
     * Get file size in bytes.
     *
     * @param string $path Relative path within the storage
     * @return int|null Size in bytes or null if not found
     */
    public function size(string $path): ?int;

    /**
     * Get file MIME type.
     *
     * @param string $path Relative path within the storage
     * @return string|null MIME type or null if not found
     */
    public function mimeType(string $path): ?string;

    /**
     * Get file last modified timestamp.
     *
     * @param string $path Relative path within the storage
     * @return int|null Unix timestamp or null if not found
     */
    public function lastModified(string $path): ?int;

    /**
     * Copy a file to a new location.
     *
     * @param string $source      Source path
     * @param string $destination Destination path
     * @return bool True on success
     */
    public function copy(string $source, string $destination): bool;

    /**
     * Move a file to a new location.
     *
     * @param string $source      Source path
     * @param string $destination Destination path
     * @return bool True on success
     */
    public function move(string $source, string $destination): bool;

    /**
     * Generate a public URL for the file.
     *
     * @param string $path Relative path within the storage
     * @return string Public URL
     */
    public function url(string $path): string;

    /**
     * Generate a temporary/signed URL for the file.
     *
     * @param string             $path       Relative path within the storage
     * @param \DateTimeInterface $expiration URL expiration time
     * @param array              $options    Driver-specific options
     * @return string Signed URL
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string;

    /**
     * List files in a directory.
     *
     * @param string $directory Directory path (empty for root)
     * @param bool   $recursive Whether to list recursively
     * @return array<string> Array of file paths
     */
    public function files(string $directory = '', bool $recursive = false): array;

    /**
     * List directories in a directory.
     *
     * @param string $directory Directory path (empty for root)
     * @param bool   $recursive Whether to list recursively
     * @return array<string> Array of directory paths
     */
    public function directories(string $directory = '', bool $recursive = false): array;

    /**
     * Create a directory.
     *
     * @param string $path Directory path
     * @return bool True on success
     */
    public function makeDirectory(string $path): bool;

    /**
     * Delete a directory.
     *
     * @param string $path Directory path
     * @return bool True on success
     */
    public function deleteDirectory(string $path): bool;

    /**
     * Get the driver name.
     *
     * @return string Driver identifier (e.g., 'local', 's3')
     */
    public function getDriver(): string;
}
