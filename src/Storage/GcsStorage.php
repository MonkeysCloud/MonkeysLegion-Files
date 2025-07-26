<?php

namespace MonkeysLegion\Files\Storage;

use Google\Cloud\Storage\StorageClient;
use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Psr7\Utils as Psr7;

/**
 * Google Cloud Storage driver.
 *
 * Notes:
 * - By default this driver does NOT make objects public. Provide
 *   'predefinedAcl' => 'publicRead' in $options on put() if you want public.
 * - For very large files, consider using resumable uploads with
 *   'resumable' => true in $options.
 */
final class GcsStorage implements FileStorage
{
    public function __construct(
        private StorageClient $client,
        private string $bucket,
        private string $prefix = '',
        private ?string $publicBaseUrl = null, // e.g. https://cdn.example.com
    ) {}

    public function name(): string
    {
        return 'gcs';
    }

    public function put(string $path, StreamInterface $stream, array $options = []): ?string
    {
        $key = $this->key($path);

        // Prefer streaming resource; fall back to full string.
        $resource = $stream->detach();
        if ($resource === null) {
            $resource = Psr7::copyToString($stream);
        }

        $bucket = $this->client->bucket($this->bucket);

        // Compose upload options.
        $opts = $options;
        $opts['name'] = $key;

        // Normalize content type.
        $mime = $options['mime'] ?? 'application/octet-stream';
        $opts['metadata']['contentType'] = $mime;

        $bucket->upload($resource, $opts);

        // Return URL only if configured. Otherwise keep it private (null).
        if ($this->publicBaseUrl) {
            return rtrim($this->publicBaseUrl, '/') . '/' . $key;
        }

        // If caller set public ACL but no CDN, provide default GCS public URL.
        if (($opts['predefinedAcl'] ?? null) === 'publicRead') {
            return sprintf('https://storage.googleapis.com/%s/%s', $this->bucket, $key);
        }

        return null;
    }

    public function delete(string $path): void
    {
        $object = $this->client->bucket($this->bucket)->object($this->key($path));
        // If it doesn't exist, delete() will throw; swallow if you prefer:
        try {
            $object->delete();
        } catch (\Throwable) {
            // ignore
        }
    }

    public function read(string $path): StreamInterface
    {
        $object = $this->client->bucket($this->bucket)->object($this->key($path));

        // downloadAsStream returns a stream-like object; convert to PSR-7 stream.
        // This reads the full object into memory. For very large objects, use
        // downloadToFile() to a temp file and stream that.
        $gcsStream = $object->downloadAsStream();
        $contents = $gcsStream->getContents();
        return Psr7::streamFor($contents);
    }

    public function exists(string $path): bool
    {
        $object = $this->client->bucket($this->bucket)->object($this->key($path));
        try {
            return $object->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Optional helper used by ml_files_url() if present. */
    public function publicUrl(string $path): string
    {
        $key = $this->key($path);

        if ($this->publicBaseUrl) {
            return rtrim($this->publicBaseUrl, '/') . '/' . $key;
        }

        return sprintf('https://storage.googleapis.com/%s/%s', $this->bucket, $key);
    }

    private function key(string $path): string
    {
        $key = ltrim($path, '/');
        if ($this->prefix !== '') {
            $key = ltrim($this->prefix, '/') . '/' . $key;
        }
        return $key;
    }
}