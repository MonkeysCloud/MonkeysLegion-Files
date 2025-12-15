<?php

declare(strict_types=1);

namespace MonkeysLegion\Files\Storage;

use DateTimeImmutable;
use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\PermissionDeniedException;
use MonkeysLegion\Files\Exception\StorageException;

/**
 * Local filesystem storage driver.
 * 
 * Stores files on the local filesystem with support for public URLs,
 * permissions, and all standard storage operations.
 */
class LocalStorage implements StorageInterface
{
    private string $basePath;
    private string $baseUrl;
    private int $directoryPermissions;
    private int $filePermissions;
    private string $visibility;

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    public function __construct(
        string $basePath,
        string $baseUrl = '',
        int $directoryPermissions = 0755,
        int $filePermissions = 0644,
        string $visibility = self::VISIBILITY_PUBLIC
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->directoryPermissions = $directoryPermissions;
        $this->filePermissions = $filePermissions;
        $this->visibility = $visibility;

        // Ensure base path exists
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath, $this->directoryPermissions, true)) {
                throw new StorageException("Cannot create base directory: {$this->basePath}");
            }
        }
    }

    public function put(string $path, string $contents, array $options = []): bool
    {
        $fullPath = $this->getFullPath($path);
        $this->ensureDirectory(dirname($fullPath));

        $result = file_put_contents($fullPath, $contents, LOCK_EX);

        if ($result === false) {
            throw new StorageException("Failed to write file: {$path}");
        }

        $this->setPermissions($fullPath, $options);

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream provided');
        }

        $fullPath = $this->getFullPath($path);
        $this->ensureDirectory(dirname($fullPath));

        $destination = fopen($fullPath, 'wb');

        if (!$destination) {
            throw new StorageException("Cannot open file for writing: {$path}");
        }

        try {
            // Lock for exclusive write
            if (!flock($destination, LOCK_EX)) {
                throw new StorageException("Cannot lock file: {$path}");
            }

            stream_copy_to_stream($stream, $destination);

            flock($destination, LOCK_UN);
        } finally {
            fclose($destination);
        }

        $this->setPermissions($fullPath, $options);

        return true;
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $contents = file_get_contents($fullPath);

        return $contents !== false ? $contents : null;
    }

    public function getStream(string $path): mixed
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $stream = fopen($fullPath, 'rb');

        return $stream !== false ? $stream : null;
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return true;
        }

        return unlink($fullPath);
    }

    public function deleteMultiple(array $paths): bool
    {
        $success = true;

        foreach ($paths as $path) {
            if (!$this->delete($path)) {
                $success = false;
            }
        }

        return $success;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->getFullPath($path));
    }

    public function size(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $size = filesize($fullPath);

        return $size !== false ? $size : null;
    }

    public function mimeType(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $mimeType !== false ? $mimeType : null;
    }

    public function lastModified(string $path): ?int
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $mtime = filemtime($fullPath);

        return $mtime !== false ? $mtime : null;
    }

    public function copy(string $source, string $destination): bool
    {
        $sourcePath = $this->getFullPath($source);
        $destPath = $this->getFullPath($destination);

        if (!file_exists($sourcePath)) {
            throw new FileNotFoundException($source);
        }

        $this->ensureDirectory(dirname($destPath));

        return copy($sourcePath, $destPath);
    }

    public function move(string $source, string $destination): bool
    {
        $sourcePath = $this->getFullPath($source);
        $destPath = $this->getFullPath($destination);

        if (!file_exists($sourcePath)) {
            throw new FileNotFoundException($source);
        }

        $this->ensureDirectory(dirname($destPath));

        return rename($sourcePath, $destPath);
    }

    public function url(string $path): string
    {
        if (empty($this->baseUrl)) {
            throw new StorageException('Base URL is not configured');
        }

        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        // For local storage, we can create a signed URL
        $signingKey = $options['signing_key'] ?? throw new StorageException(
            'Signing key required for temporary URLs'
        );

        $expires = $expiration->getTimestamp();
        $signature = hash_hmac('sha256', $path . "\n" . $expires, $signingKey);
        $signature = rtrim(strtr(base64_encode(hex2bin($signature)), '+/', '-_'), '=');

        $url = $this->url($path);
        $separator = str_contains($url, '?') ? '&' : '?';

        return sprintf('%s%sexpires=%d&signature=%s', $url, $separator, $expires, $signature);
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $this->getRelativePath($file->getPathname());
                }
            }
        } else {
            $iterator = new \DirectoryIterator($fullPath);

            foreach ($iterator as $file) {
                if (!$file->isDot() && $file->isFile()) {
                    $files[] = $this->getRelativePath($file->getPathname());
                }
            }
        }

        return $files;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $fullPath = $this->getFullPath($directory);

        if (!is_dir($fullPath)) {
            return [];
        }

        $directories = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    $directories[] = $this->getRelativePath($file->getPathname());
                }
            }
        } else {
            $iterator = new \DirectoryIterator($fullPath);

            foreach ($iterator as $file) {
                if (!$file->isDot() && $file->isDir()) {
                    $directories[] = $this->getRelativePath($file->getPathname());
                }
            }
        }

        return $directories;
    }

    public function makeDirectory(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (is_dir($fullPath)) {
            return true;
        }

        return mkdir($fullPath, $this->directoryPermissions, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $fullPath = $this->getFullPath($path);

        if (!is_dir($fullPath)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        return rmdir($fullPath);
    }

    public function getDriver(): string
    {
        return 'local';
    }

    /**
     * Get the full filesystem path.
     */
    public function getFullPath(string $path): string
    {
        $path = ltrim($path, '/');
        $fullPath = $this->basePath . '/' . $path;

        // Prevent directory traversal
        $realBase = realpath($this->basePath);
        $realPath = realpath(dirname($fullPath));

        if ($realPath !== false && !str_starts_with($realPath, $realBase)) {
            throw new PermissionDeniedException($path, 'access');
        }

        return $fullPath;
    }

    /**
     * Get relative path from full path.
     */
    private function getRelativePath(string $fullPath): string
    {
        return ltrim(substr($fullPath, strlen($this->basePath)), '/');
    }

    /**
     * Ensure directory exists.
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, $this->directoryPermissions, true)) {
                throw new StorageException("Cannot create directory: {$directory}");
            }
        }
    }

    /**
     * Set file permissions based on visibility.
     */
    private function setPermissions(string $fullPath, array $options): void
    {
        $visibility = $options['visibility'] ?? $this->visibility;

        $permissions = match ($visibility) {
            self::VISIBILITY_PUBLIC => 0644,
            self::VISIBILITY_PRIVATE => 0600,
            default => $this->filePermissions,
        };

        chmod($fullPath, $permissions);
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $contents): bool
    {
        $fullPath = $this->getFullPath($path);
        $this->ensureDirectory(dirname($fullPath));

        $result = file_put_contents($fullPath, $contents, FILE_APPEND | LOCK_EX);

        return $result !== false;
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $contents): bool
    {
        $existing = $this->get($path) ?? '';
        return $this->put($path, $contents . $existing);
    }

    /**
     * Get file checksum.
     */
    public function checksum(string $path, string $algorithm = 'sha256'): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        return hash_file($algorithm, $fullPath);
    }

    /**
     * Set file visibility.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return false;
        }

        $this->setPermissions($fullPath, ['visibility' => $visibility]);

        return true;
    }

    /**
     * Get file visibility.
     */
    public function getVisibility(string $path): ?string
    {
        $fullPath = $this->getFullPath($path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $perms = fileperms($fullPath);
        $worldReadable = ($perms & 0004) !== 0;

        return $worldReadable ? self::VISIBILITY_PUBLIC : self::VISIBILITY_PRIVATE;
    }
}
