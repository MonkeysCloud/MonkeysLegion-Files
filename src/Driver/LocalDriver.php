<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Driver;

use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Exception\SecurityException;
use MonkeysLegion\Files\Exception\StorageException;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Local filesystem storage driver with directory-traversal prevention,
 * atomic writes (tmp + rename), and visibility via POSIX permissions.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class LocalDriver implements StorageInterface
{
    /** Resolved real path of the base directory. */
    private readonly string $resolvedBase;

    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = '',
        private readonly int $dirPermissions = 0o755,
        private readonly int $filePermissions = 0o644,
        private readonly Visibility $defaultVisibility = Visibility::Public,
    ) {
        $normalized = rtrim($this->basePath, '/');

        if (!is_dir($normalized) && !mkdir($normalized, $this->dirPermissions, true)) {
            throw new StorageException("Cannot create base directory: {$normalized}");
        }

        $real = realpath($normalized);

        if ($real === false) {
            throw new StorageException("Cannot resolve base directory: {$normalized}");
        }

        $this->resolvedBase = $real;
    }

    // ── Write Operations ─────────────────────────────────────────

    public function put(string $path, string $contents, array $options = []): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDir(dirname($full));

        // Atomic write: write to tmp, then rename
        $tmp = $full . '.tmp.' . bin2hex(random_bytes(4));

        $result = file_put_contents($tmp, $contents, LOCK_EX);

        if ($result === false) {
            @unlink($tmp);
            throw new StorageException("Failed to write file: {$path}");
        }

        if (!rename($tmp, $full)) {
            @unlink($tmp);
            throw new StorageException("Failed to commit file: {$path}");
        }

        $this->applyPermissions($full, $options['visibility'] ?? $this->defaultVisibility);

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('Invalid stream provided');
        }

        $full = $this->fullPath($path);
        $this->ensureDir(dirname($full));

        $tmp  = $full . '.tmp.' . bin2hex(random_bytes(4));
        $dest = fopen($tmp, 'wb');

        if ($dest === false) {
            throw new StorageException("Cannot open for writing: {$path}");
        }

        try {
            flock($dest, LOCK_EX);
            stream_copy_to_stream($stream, $dest);
            flock($dest, LOCK_UN);
        } finally {
            fclose($dest);
        }

        if (!rename($tmp, $full)) {
            @unlink($tmp);
            throw new StorageException("Failed to commit file: {$path}");
        }

        $this->applyPermissions($full, $options['visibility'] ?? $this->defaultVisibility);

        return true;
    }

    public function append(string $path, string $contents): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDir(dirname($full));

        return file_put_contents($full, $contents, FILE_APPEND | LOCK_EX) !== false;
    }

    public function prepend(string $path, string $contents): bool
    {
        $existing = $this->get($path) ?? '';

        return $this->put($path, $contents . $existing);
    }

    // ── Read Operations ──────────────────────────────────────────

    public function get(string $path): ?string
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $contents = file_get_contents($full);

        return $contents !== false ? $contents : null;
    }

    public function getStream(string $path): mixed
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $stream = fopen($full, 'rb');

        return $stream !== false ? $stream : null;
    }

    // ── Delete Operations ────────────────────────────────────────

    public function delete(string $path): bool
    {
        $full = $this->fullPath($path);

        if (!file_exists($full)) {
            return true;
        }

        return unlink($full);
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function size(string $path): ?int
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $size = filesize($full);

        return $size !== false ? $size : null;
    }

    public function mimeType(string $path): ?string
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        // Content-based sniffing (not extension-based)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($full);

        return $mime !== false ? $mime : null;
    }

    public function lastModified(string $path): ?int
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $mtime = filemtime($full);

        return $mtime !== false ? $mtime : null;
    }

    public function checksum(string $path, string $algo = 'sha256'): ?string
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $hash = hash_file($algo, $full);

        return $hash !== false ? $hash : null;
    }

    // ── Visibility ───────────────────────────────────────────────

    public function visibility(string $path): ?Visibility
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            return null;
        }

        $perms = fileperms($full);
        $worldReadable = ($perms & 0o004) !== 0;

        return $worldReadable ? Visibility::Public : Visibility::Private;
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $full = $this->fullPath($path);

        if (!is_file($full)) {
            throw new FileNotFoundException($path);
        }

        $this->applyPermissions($full, $visibility);
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function copy(string $source, string $destination): bool
    {
        $srcFull  = $this->fullPath($source);
        $destFull = $this->fullPath($destination);

        if (!is_file($srcFull)) {
            throw new FileNotFoundException($source);
        }

        $this->ensureDir(dirname($destFull));

        return copy($srcFull, $destFull);
    }

    public function move(string $source, string $destination): bool
    {
        $srcFull  = $this->fullPath($source);
        $destFull = $this->fullPath($destination);

        if (!is_file($srcFull)) {
            throw new FileNotFoundException($source);
        }

        $this->ensureDir(dirname($destFull));

        return rename($srcFull, $destFull);
    }

    // ── Directory Operations ─────────────────────────────────────

    public function url(string $path): string
    {
        if ($this->baseUrl === '') {
            throw new StorageException('Base URL is not configured');
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $full = $this->fullPath($directory);

        if (!is_dir($full)) {
            return [];
        }

        $results = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $results[] = $this->relativePath($file->getPathname());
                }
            }
        } else {
            foreach (new \DirectoryIterator($full) as $file) {
                if (!$file->isDot() && $file->isFile()) {
                    $results[] = $this->relativePath($file->getPathname());
                }
            }
        }

        sort($results);

        return $results;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $full = $this->fullPath($directory);

        if (!is_dir($full)) {
            return [];
        }

        $results = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    $results[] = $this->relativePath($file->getPathname());
                }
            }
        } else {
            foreach (new \DirectoryIterator($full) as $file) {
                if (!$file->isDot() && $file->isDir()) {
                    $results[] = $this->relativePath($file->getPathname());
                }
            }
        }

        sort($results);

        return $results;
    }

    public function makeDirectory(string $path): bool
    {
        $full = $this->fullPath($path);

        if (is_dir($full)) {
            return true;
        }

        return mkdir($full, $this->dirPermissions, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $full = $this->fullPath($path);

        if (!is_dir($full)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        return rmdir($full);
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function getDriver(): string
    {
        return 'local';
    }

    // ── Internal Helpers ─────────────────────────────────────────

    /**
     * Resolve a relative path to a full filesystem path, with
     * directory-traversal prevention.
     */
    private function fullPath(string $path): string
    {
        $path = ltrim($path, '/');

        // Reject any path containing '..' traversal segments
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new SecurityException("Path traversal detected: {$path}");
        }

        $full = $this->resolvedBase . '/' . $path;

        // Build prefix that correctly handles root (/) base path
        $basePrefix = rtrim($this->resolvedBase, '/');
        $basePrefix = ($basePrefix === '') ? '/' : $basePrefix . '/';

        // If parent directory exists, verify it's within base
        $parent = realpath(dirname($full));

        if (
            $parent !== false
            && $parent !== $this->resolvedBase
            && !str_starts_with($parent, $basePrefix)
        ) {
            throw new SecurityException("Path traversal detected: {$path}");
        }

        return $full;
    }

    /** Get relative path from a full filesystem path. */
    private function relativePath(string $fullPath): string
    {
        return ltrim(substr($fullPath, strlen($this->resolvedBase)), '/');
    }

    /** Ensure a directory exists. */
    private function ensureDir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, $this->dirPermissions, true)) {
            throw new StorageException("Cannot create directory: {$directory}");
        }
    }

    /** Apply POSIX permissions based on visibility. */
    private function applyPermissions(string $fullPath, Visibility $visibility): void
    {
        $perms = match ($visibility) {
            Visibility::Public  => 0o644,
            Visibility::Private => 0o600,
        };

        chmod($fullPath, $perms);
    }
}
