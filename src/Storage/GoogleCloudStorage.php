<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Storage;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Exception\FileNotFoundException;

/**
 * Google Cloud Storage driver.
 * 
 * Supports all GCS features including signed URLs, resumable uploads,
 * lifecycle management, and IAM-based access control.
 * 
 * @requires google/cloud-storage ^1.35
 */
final class GoogleCloudStorage implements StorageInterface
{
    private StorageClient $client;
    private Bucket $bucket;
    private string $bucketName;
    private string $visibility;
    private ?string $pathPrefix;
    private ?string $publicUrl;

    /**
     * @param string      $bucketName    GCS bucket name
     * @param string|null $projectId     Google Cloud project ID
     * @param string|null $keyFilePath   Path to service account JSON key file
     * @param array|null  $keyFile       Service account key as array (alternative to keyFilePath)
     * @param string      $visibility    Default visibility: 'public' or 'private'
     * @param string|null $pathPrefix    Optional path prefix for all operations
     * @param string|null $publicUrl     Custom public URL (for CDN or custom domain)
     * @param array       $options       Additional options
     */
    public function __construct(
        string $bucketName,
        ?string $projectId = null,
        ?string $keyFilePath = null,
        ?array $keyFile = null,
        string $visibility = 'private',
        ?string $pathPrefix = null,
        ?string $publicUrl = null,
        array $options = [],
    ) {
        $this->bucketName = $bucketName;
        $this->visibility = $visibility;
        $this->pathPrefix = $pathPrefix ? trim($pathPrefix, '/') : null;
        $this->publicUrl = $publicUrl ? rtrim($publicUrl, '/') : null;


        $clientConfig = [];

        if ($projectId) {
            $clientConfig['projectId'] = $projectId;
        }

        if ($keyFilePath) {
            $clientConfig['keyFilePath'] = $keyFilePath;
        } elseif ($keyFile) {
            $clientConfig['keyFile'] = $keyFile;
        }

        // Support for emulator (for local development)
        if (isset($options['api_endpoint'])) {
            $clientConfig['apiEndpoint'] = $options['api_endpoint'];
        }

        $this->client = new StorageClient($clientConfig);
        $this->bucket = $this->client->bucket($bucketName);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents, array $options = []): bool
    {
        $path = $this->prefixPath($path);

        try {
            $uploadOptions = $this->buildUploadOptions($options);
            $uploadOptions['name'] = $path;

            $this->bucket->upload($contents, $uploadOptions);

            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to write to GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        $path = $this->prefixPath($path);

        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream resource provided');
        }

        try {
            $uploadOptions = $this->buildUploadOptions($options);
            $uploadOptions['name'] = $path;

            $this->bucket->upload($stream, $uploadOptions);

            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to write stream to GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            return $object->downloadAsString();
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to read from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(string $path): mixed
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            
            $psrStream = $object->downloadAsStream();
            $resource = $psrStream->detach();
            
            if (!is_resource($resource)) {
                return null;
            }
            
            return $resource;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to get stream from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $object->delete();
            return true;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return true; // Already deleted
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to delete from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $paths): bool
    {
        $success = true;

        foreach ($paths as $path) {
            try {
                $this->delete($path);
            } catch (\Throwable) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $path = $this->prefixPath($path);

        try {
            return $this->bucket->object($path)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $path): ?int
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $info = $object->info();
            return (int) ($info['size'] ?? 0);
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to get size from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): ?string
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $info = $object->info();
            return $info['contentType'] ?? null;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to get MIME type from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $info = $object->info();
            
            if (isset($info['updated'])) {
                return strtotime($info['updated']);
            }
            
            return null;
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to get last modified from GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination): bool
    {
        $source = $this->prefixPath($source);
        $destination = $this->prefixPath($destination);

        try {
            $sourceObject = $this->bucket->object($source);
            $sourceObject->copy($this->bucketName, ['name' => $destination]);
            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to copy in GCS: {$source} -> {$destination}",
                previous: $e,
                context: ['source' => $source, 'destination' => $destination, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination): bool
    {
        if ($this->copy($source, $destination)) {
            return $this->delete($source);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        $path = $this->prefixPath($path);

        if ($this->publicUrl) {
            return $this->publicUrl . '/' . ltrim($path, '/');
        }

        // Default GCS public URL format
        return sprintf(
            'https://storage.googleapis.com/%s/%s',
            $this->bucketName,
            $path
        );
    }

    /**
     * {@inheritdoc}
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);

            $signedUrlOptions = [
                'version' => 'v4',
            ];

            // Response content type
            if (isset($options['ResponseContentType'])) {
                $signedUrlOptions['responseType'] = $options['ResponseContentType'];
            }

            // Response content disposition
            if (isset($options['ResponseContentDisposition'])) {
                $signedUrlOptions['responseDisposition'] = $options['ResponseContentDisposition'];
            }

            // Save as filename shortcut
            if (isset($options['save_as'])) {
                $signedUrlOptions['responseDisposition'] = 'attachment; filename="' . $options['save_as'] . '"';
            }

            return $object->signedUrl($expiration, $signedUrlOptions);
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to generate signed URL for GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * Generate a signed URL for uploading (direct upload from browser).
     *
     * @param string $path        Destination path
     * @param string $contentType Expected content type
     * @param int    $ttl         Time to live in seconds
     * @param array  $options     Additional options
     * @return string Signed upload URL
     */
    public function getUploadUrl(string $path, string $contentType, int $ttl = 3600, array $options = []): string
    {
        $path = $this->prefixPath($path);
        $expiration = new \DateTimeImmutable("+{$ttl} seconds");

        try {
            $object = $this->bucket->object($path);

            return $object->signedUrl($expiration, [
                'method' => 'PUT',
                'contentType' => $contentType,
                'version' => 'v4',
            ]);
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to generate upload URL for GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * Start a resumable upload session.
     *
     * @param string $path        Destination path
     * @param string $contentType Content type
     * @param int    $contentLength Total file size
     * @param array  $metadata    Optional metadata
     * @return string Resumable upload URI
     */
    public function initiateResumableUpload(
        string $path,
        string $contentType,
        int $contentLength,
        array $metadata = []
    ): string {
        $path = $this->prefixPath($path);

        try {
            $options = [
                'name' => $path,
                'resumable' => true,
                'metadata' => array_merge([
                    'contentType' => $contentType,
                ], $metadata),
            ];

            // Create an empty placeholder to get the resumable URI
            $uploader = $this->bucket->getResumableUploader(
                fopen('php://temp', 'r'),
                $options
            );

            return $uploader->getResumeUri();
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to initiate resumable upload for GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = $this->prefixPath($directory);
        $directory = $directory ? rtrim($directory, '/') . '/' : '';

        try {
            $options = [
                'prefix' => $directory,
            ];

            if (!$recursive) {
                $options['delimiter'] = '/';
            }

            $files = [];
            $objects = $this->bucket->objects($options);

            foreach ($objects as $object) {
                $name = $object->name();
                
                // Skip directory placeholders
                if (str_ends_with($name, '/')) {
                    continue;
                }

                // Remove prefix if set
                if ($this->pathPrefix) {
                    $name = preg_replace('#^' . preg_quote($this->pathPrefix . '/', '#') . '#', '', $name);
                }

                $files[] = $name;
            }

            return $files;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to list files in GCS: {$directory}",
                previous: $e,
                context: ['directory' => $directory, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        $directory = $this->prefixPath($directory);
        $directory = $directory ? rtrim($directory, '/') . '/' : '';

        try {
            $options = [
                'prefix' => $directory,
                'delimiter' => '/',
            ];

            $directories = [];
            $objects = $this->bucket->objects($options);

            // Get prefixes (directories) from the iterator info
            foreach ($objects->iterateByPage() as $page) {
                $pageInfo = $page->getIterator()->current();
                // Prefixes are returned separately in GCS
            }

            // Alternative: scan all objects and extract unique directories
            $allObjects = $this->bucket->objects(['prefix' => $directory]);
            $seen = [];

            foreach ($allObjects as $object) {
                $name = $object->name();
                
                // Extract directory paths
                $relativePath = substr($name, strlen($directory));
                $parts = explode('/', $relativePath);
                
                if (count($parts) > 1) {
                    $dir = $directory . $parts[0];
                    
                    if (!isset($seen[$dir])) {
                        $seen[$dir] = true;
                        
                        // Remove prefix if set
                        $cleanDir = $dir;
                        if ($this->pathPrefix) {
                            $cleanDir = preg_replace('#^' . preg_quote($this->pathPrefix . '/', '#') . '#', '', $dir);
                        }
                        
                        $directories[] = rtrim($cleanDir, '/');
                    }
                }
            }

            return array_unique($directories);
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to list directories in GCS: {$directory}",
                previous: $e,
                context: ['directory' => $directory, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function makeDirectory(string $path): bool
    {
        // GCS doesn't have real directories, but we can create a placeholder
        $path = $this->prefixPath($path);
        $path = rtrim($path, '/') . '/';

        try {
            $this->bucket->upload('', [
                'name' => $path,
                'metadata' => [
                    'contentType' => 'application/x-directory',
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to create directory in GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $path): bool
    {
        $path = $this->prefixPath($path);
        $path = rtrim($path, '/') . '/';

        try {
            $objects = $this->bucket->objects(['prefix' => $path]);
            
            foreach ($objects as $object) {
                $object->delete();
            }

            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to delete directory in GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): string
    {
        return 'gcs';
    }

    /**
     * Get object metadata.
     *
     * @param string $path File path
     * @return array|null Metadata array or null if not found
     */
    public function getMetadata(string $path): ?array
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            return $object->info();
        } catch (\Google\Cloud\Core\Exception\NotFoundException) {
            return null;
        }
    }

    /**
     * Update object metadata.
     *
     * @param string $path     File path
     * @param array  $metadata Metadata to set
     * @return bool
     */
    public function setMetadata(string $path, array $metadata): bool
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $object->update(['metadata' => $metadata]);
            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to update metadata in GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName]
            );
        }
    }

    /**
     * Set object visibility (ACL).
     *
     * @param string $path       File path
     * @param string $visibility 'public' or 'private'
     * @return bool
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $acl = $object->acl();

            if ($visibility === 'public') {
                $acl->add('allUsers', 'READER');
            } else {
                $acl->delete('allUsers');
            }

            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to set visibility in GCS: {$path}",
                previous: $e,
                context: ['path' => $path, 'bucket' => $this->bucketName, 'visibility' => $visibility]
            );
        }
    }

    /**
     * Get object visibility.
     *
     * @param string $path File path
     * @return string 'public' or 'private'
     */
    public function getVisibility(string $path): string
    {
        $path = $this->prefixPath($path);

        try {
            $object = $this->bucket->object($path);
            $acl = $object->acl()->get();

            foreach ($acl as $entry) {
                if (($entry['entity'] ?? '') === 'allUsers') {
                    return 'public';
                }
            }

            return 'private';
        } catch (\Throwable) {
            return 'private';
        }
    }

    /**
     * Compose multiple objects into one.
     *
     * @param array  $sourcePaths   Array of source paths
     * @param string $destinationPath Destination path
     * @return bool
     */
    public function compose(array $sourcePaths, string $destinationPath): bool
    {
        $destinationPath = $this->prefixPath($destinationPath);
        $sourceObjects = [];

        foreach ($sourcePaths as $sourcePath) {
            $sourceObjects[] = $this->bucket->object($this->prefixPath($sourcePath));
        }

        try {
            $this->bucket->compose($sourceObjects, $destinationPath);
            return true;
        } catch (\Throwable $e) {
            throw new StorageException(
                "Failed to compose objects in GCS",
                previous: $e,
                context: ['sources' => $sourcePaths, 'destination' => $destinationPath]
            );
        }
    }

    /**
     * Get the underlying GCS client.
     *
     * @return StorageClient
     */
    public function getClient(): StorageClient
    {
        return $this->client;
    }

    /**
     * Get the bucket instance.
     *
     * @return Bucket
     */
    public function getBucket(): Bucket
    {
        return $this->bucket;
    }

    /**
     * Prefix a path with the configured prefix.
     */
    private function prefixPath(string $path): string
    {
        $path = ltrim($path, '/');

        if ($this->pathPrefix) {
            return $this->pathPrefix . '/' . $path;
        }

        return $path;
    }

    /**
     * Build upload options from user options.
     */
    private function buildUploadOptions(array $options): array
    {
        $uploadOptions = [];

        // Content type
        if (isset($options['ContentType']) || isset($options['content_type'])) {
            $uploadOptions['metadata']['contentType'] = $options['ContentType'] ?? $options['content_type'];
        }

        // Cache control
        if (isset($options['CacheControl']) || isset($options['cache_control'])) {
            $uploadOptions['metadata']['cacheControl'] = $options['CacheControl'] ?? $options['cache_control'];
        }

        // Content disposition
        if (isset($options['ContentDisposition']) || isset($options['content_disposition'])) {
            $uploadOptions['metadata']['contentDisposition'] = $options['ContentDisposition'] ?? $options['content_disposition'];
        }

        // Content encoding
        if (isset($options['ContentEncoding']) || isset($options['content_encoding'])) {
            $uploadOptions['metadata']['contentEncoding'] = $options['ContentEncoding'] ?? $options['content_encoding'];
        }

        // Custom metadata
        if (isset($options['Metadata']) || isset($options['metadata'])) {
            $uploadOptions['metadata']['metadata'] = $options['Metadata'] ?? $options['metadata'];
        }

        // Visibility / ACL
        $visibility = $options['visibility'] ?? $this->visibility;
        if ($visibility === 'public') {
            $uploadOptions['predefinedAcl'] = 'publicRead';
        } else {
            $uploadOptions['predefinedAcl'] = 'private';
        }

        // Storage class
        if (isset($options['storage_class'])) {
            $uploadOptions['metadata']['storageClass'] = $options['storage_class'];
        }

        return $uploadOptions;
    }
}
