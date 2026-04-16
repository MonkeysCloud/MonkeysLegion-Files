<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Contracts;

use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Core contract for all storage drivers. Every implementation (local, S3,
 * GCS, memory) must satisfy this interface for interchangeable usage.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface StorageInterface
{
    // ── Write Operations ─────────────────────────────────────────

    /**
     * Store a file from string contents.
     *
     * @param string               $path     Relative path within the storage
     * @param string               $contents File contents
     * @param array<string, mixed> $options  Driver-specific options
     */
    public function put(string $path, string $contents, array $options = []): bool;

    /**
     * Store a file from a stream resource.
     *
     * @param string               $path    Relative path within the storage
     * @param resource             $stream  Input stream (must be readable)
     * @param array<string, mixed> $options Driver-specific options
     */
    public function putStream(string $path, mixed $stream, array $options = []): bool;

    /**
     * Append data to an existing file.
     */
    public function append(string $path, string $contents): bool;

    /**
     * Prepend data to an existing file.
     */
    public function prepend(string $path, string $contents): bool;

    // ── Read Operations ──────────────────────────────────────────

    /**
     * Get file contents as a string.
     *
     * @return string|null Contents or null if file does not exist
     */
    public function get(string $path): ?string;

    /**
     * Get a readable stream for the file.
     *
     * @return resource|null Stream resource or null if not found
     */
    public function getStream(string $path): mixed;

    // ── Delete Operations ────────────────────────────────────────

    /**
     * Delete a file. Returns true if deleted or already absent.
     */
    public function delete(string $path): bool;

    // ── Metadata ─────────────────────────────────────────────────

    /** Check if a file exists. */
    public function exists(string $path): bool;

    /** Get file size in bytes. */
    public function size(string $path): ?int;

    /** Get MIME type via content sniffing. */
    public function mimeType(string $path): ?string;

    /** Get last‑modified timestamp. */
    public function lastModified(string $path): ?int;

    /**
     * Compute a file checksum.
     *
     * @param string $algo Hash algorithm (sha256, md5, etc.)
     */
    public function checksum(string $path, string $algo = 'sha256'): ?string;

    // ── Visibility ───────────────────────────────────────────────

    /** Get the file's visibility. */
    public function visibility(string $path): ?Visibility;

    /** Set the file's visibility. */
    public function setVisibility(string $path, Visibility $visibility): void;

    // ── Copy / Move ──────────────────────────────────────────────

    /** Copy a file to a new location within the same driver. */
    public function copy(string $source, string $destination): bool;

    /** Move a file to a new location within the same driver. */
    public function move(string $source, string $destination): bool;

    // ── Directory Operations ─────────────────────────────────────

    /** Generate a public URL for the file. */
    public function url(string $path): string;

    /**
     * List files in a directory.
     *
     * @return list<string>
     */
    public function files(string $directory = '', bool $recursive = false): array;

    /**
     * List subdirectories.
     *
     * @return list<string>
     */
    public function directories(string $directory = '', bool $recursive = false): array;

    /** Create a directory. */
    public function makeDirectory(string $path): bool;

    /** Delete a directory and its contents. */
    public function deleteDirectory(string $path): bool;

    // ── Driver Identity ──────────────────────────────────────────

    /** Return the driver name (e.g. 'local', 's3', 'gcs', 'memory'). */
    public function getDriver(): string;
}
