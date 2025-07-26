<?php

namespace MonkeysLegion\Files\Contracts;

use Psr\Http\Message\StreamInterface;

/**
 * Interface FileStorage
 *
 * This interface defines the contract for file storage services.
 * Implementations should provide methods to store, delete, read, and check the existence of files.
 */
interface FileStorage
{
    /**
     * Return the name of the storage service.
     *
     * This is used to identify the storage service in logs and other outputs.
     */
    public function name(): string;

    /**
     * Store the given stream at the specified path.
     *
     * @param string $path The path where the file should be stored.
     * @param StreamInterface $stream The stream containing the file data.
     * @param array $options Additional options for storage, if any.
     * @return string|null Returns the public URL of the stored file or null if private.
     */
    public function put(string $path, StreamInterface $stream, array $options = []): ?string;

    /**
     * Delete the file at the specified path.
     *
     * @param string $path The path of the file to delete.
     */
    public function delete(string $path): void;

    /**
     * Read the file at the specified path and return it as a stream.
     *
     * @param string $path The path of the file to read.
     * @return StreamInterface The stream containing the file data.
     */
    public function read(string $path): StreamInterface;

    /**
     * Check if a file exists at the specified path.
     *
     * @param string $path The path to check for existence.
     * @return bool Returns true if the file exists, false otherwise.
     */
    public function exists(string $path): bool;

}
