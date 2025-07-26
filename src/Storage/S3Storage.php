<?php

namespace MonkeysLegion\Files\Storage;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileStorage;
use Psr\Http\Message\StreamInterface;

/** Optional driver, requires aws/aws-sdk-php */
final class S3Storage implements FileStorage
{
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix = '',
        private ?string $publicBaseUrl = null,
    ) {}

    public function name(): string { return 's3'; }

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

    public function delete(string $path): void
    {
        $key = ltrim($this->prefix.$path, '/');
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    public function read(string $path): StreamInterface
    {
        $key = ltrim($this->prefix.$path, '/');
        $res = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
        return Psr7::streamFor($res['Body']);
    }

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
