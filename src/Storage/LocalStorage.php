<?php
namespace MonkeysLegion\Files\Storage;

use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Psr7\Utils as Psr7;

/**
 * Local file storage implementation.
 * Stores files on the local filesystem and optionally provides a public URL.
 *
 * @see FileStorage
 */
final class LocalStorage implements FileStorage
{
    public function __construct(
        private string $root,
        private ?string $publicBaseUrl = null
    ) {}

    /**
     * Returns the name of this storage type.
     *
     * @return string
     */
    public function name(): string { return 'local'; }

    /**
     * Store the given stream at the specified path.
     * Returns a public URL if configured, or null for private storage.
     *
     * @param string $path The relative path where the file should be stored.
     * @param StreamInterface $stream The stream containing the file data.
     * @param array $options Additional options (not used in this implementation).
     * @return string|null The public URL or null if not configured.
     */
    public function put(string $path, StreamInterface $stream, array $options = []): ?string
    {
        $rel = ltrim($path, '/');
        $abs = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;

        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // write stream
        $out = fopen($abs, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Unable to open file for write: {$abs}");
        }
        $in = $stream->detach() ?: Psr7::copyToString($stream);
        if (is_resource($in)) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        } else {
            fwrite($out, $in);
        }
        fclose($out);

        if ($this->publicBaseUrl) {
            // support both "files" and "/files"
            $base = rtrim($this->publicBaseUrl, '/');
            $base = preg_match('#^https?://#i', $base) ? $base : '/' . ltrim($base, '/');
            return $base . '/' . $rel;
        }

        return null;
    }

    /**
     * Delete the file at the specified path.
     *
     * @param string $path The relative path of the file to delete.
     */
    public function delete(string $path): void
    {
        $abs = rtrim($this->root, '/') . '/' . ltrim($path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Read the file at the specified path and return it as a stream.
     *
     * @param string $path The relative path of the file to read.
     * @return StreamInterface The stream containing the file data.
     * @throws \RuntimeException If the file does not exist.
     */
    public function read(string $path): StreamInterface
    {
        $abs = rtrim($this->root, '/') . '/' . ltrim($path, '/');
        if (!is_file($abs)) {
            throw new \RuntimeException("File not found: {$abs}");
        }
        return Psr7::streamFor(fopen($abs, 'rb'));
    }

    /**
     * Check if a file exists at the specified path.
     *
     * @param string $path The relative path of the file to check.
     * @return bool True if the file exists, false otherwise.
     */
    public function exists(string $path): bool
    {
        $abs = rtrim($this->root, '/') . '/' . ltrim($path, '/');
        return is_file($abs);
    }

    /**
     * Generate a public URL for the given path.
     * Returns null if no public base URL is configured.
     *
     * @param string $path The relative path to generate the URL for.
     * @return string|null The public URL or null if not configured.
     */
    public function publicUrl(string $path): ?string
    {
        if (!$this->publicBaseUrl) return null;
        $base = rtrim($this->publicBaseUrl, '/');
        $base = preg_match('#^https?://#i', $base) ? $base : '/' . ltrim($base, '/');
        return $base . '/' . ltrim($path, '/');
    }
}