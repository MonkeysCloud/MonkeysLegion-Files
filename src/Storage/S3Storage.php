<?php

namespace MonkeysLegion\Files\Storage;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;

/**
 * S3 file storage implementation.
 */
final class S3Storage implements FileStorage
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix = '',
        private ?string $publicBaseUrl = null,
    ) {}

    /** Returns the name of this storage implementation. */
    public function name(): string { return 's3'; }

    /**
     * Store the given stream at the specified path in S3.
     * Creates the object with the specified content type and returns a public URL if configured.
     *
     * @param string $path The relative path where the file should be stored.
     * @param StreamInterface $stream The stream containing the file data.
     * @param array $options Additional options (e.g., 'mime' for content type).
     * @return string|null The public URL of the stored file, or null if not public.
     */
    public function put(string $path, StreamInterface $stream, array $options = []): ?string
    {
        $key = ltrim($this->prefix.$path, '/');
        $this->s3->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => Psr7::copyToString($stream),
            'ContentType' => $options['mime'] ?? 'application/octet-stream',
        ]);
        return $this->publicBaseUrl ? rtrim($this->publicBaseUrl,'/').'/'.$key : null;
    }

    /**
     * Delete the file at the specified path in S3.
     * If the object exists, it will be removed from the bucket.
     *
     * @param string $path The relative path of the file to delete.
     */
    public function delete(string $path): void
    {
        $key = ltrim($this->prefix.$path, '/');
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    /**
     * Read the file at the specified path in S3 and return it as a stream.
     * If the object does not exist, an exception will be thrown.
     *
     * @param string $path The relative path of the file to read.
     * @return StreamInterface The stream containing the file data.
     */
    public function read(string $path): StreamInterface
    {
        $key = ltrim($this->prefix.$path, '/');
        $res = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
        return Psr7::streamFor($res['Body']);
    }

    /**
     * Check if a file exists at the specified path in S3.
     * Returns true if the object exists, false otherwise.
     *
     * @param string $path The relative path of the file to check.
     * @return bool True if the file exists, false otherwise.
     */
    public function exists(string $path): bool
    {
        $key = ltrim($this->prefix.$path, '/');
        try {
            $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
