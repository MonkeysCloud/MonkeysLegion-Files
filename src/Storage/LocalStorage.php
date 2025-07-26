<?php

namespace MonkeysLegion\Files\Storage;

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;

/** Local file storage implementation.
 * Stores files in the local filesystem, optionally providing a public URL base.
 *
 * @package MonkeysLegion\Files\Storage
 */
final class LocalStorage implements FileStorage
{
    public function __construct(
        private string $root,
        private ?string $publicBaseUrl = null,
    ) {}

    /** Returns the name of this storage implementation. */
    public function name(): string { return 'local'; }

    /**
     * Store the given stream at the specified path.
     * Creates directories as needed and writes the stream to a file.
     * Returns a public URL if configured, or null for private storage.
     *
     * @param string $path The relative path where the file should be stored.
     * @param StreamInterface $stream The stream containing the file data.
     * @param array $options Additional options (not used in this implementation).
     * @return string|null The public URL of the stored file, or null if not public.
     */
    public function put(string $path, StreamInterface $stream, array $options = []): ?string
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        @mkdir(dirname($abs), 0775, true);
        $fh = fopen($abs, 'wb');
        while (!$stream->eof()) {
            fwrite($fh, $stream->read(8192));
        }
        fclose($fh);
        return $this->publicBaseUrl ? rtrim($this->publicBaseUrl, '/').'/'.$path : null;
    }

    /**
     * Delete the file at the specified path.
     * If the file exists, it will be removed from the filesystem.
     *
     * @param string $path The relative path of the file to delete.
     */
    public function delete(string $path): void
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        if (is_file($abs)) @unlink($abs);
    }

    /**
     * Read the file at the specified path and return it as a stream.
     * If the file does not exist, an exception will be thrown.
     *
     * @param string $path The relative path of the file to read.
     * @return StreamInterface The stream containing the file data.
     */
    public function read(string $path): StreamInterface
    {
        $abs = rtrim($this->root, '/').'/'.ltrim($path, '/');
        $handle = fopen($abs, 'rb');
        return Psr7::streamFor($handle);
    }

    /**
     * Check if a file exists at the specified path.
     * Returns true if the file exists, false otherwise.
     *
     * @param string $path The relative path of the file to check.
     * @return bool True if the file exists, false otherwise.
     */
    public function exists(string $path): bool
    {
        return is_file(rtrim($this->root, '/').'/'.ltrim($path, '/'));
    }
}
