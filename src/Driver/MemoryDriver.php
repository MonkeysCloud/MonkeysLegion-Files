<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Driver;

use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\Exception\FileNotFoundException;
use MonkeysLegion\Files\Visibility;

/**
 * MonkeysLegion Framework — Files Package
 *
 * In-memory storage driver for unit testing. Zero I/O, deterministic,
 * full interface compliance. **Unique to ML Files — neither Laravel
 * nor Symfony ships this.**
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MemoryDriver implements StorageInterface
{
    /** @var array<string, string> path => contents */
    private array $files = [];

    /** @var array<string, Visibility> path => visibility */
    private array $visibilities = [];

    /** @var array<string, int> path => timestamp */
    private array $timestamps = [];

    public function __construct(
        private readonly Visibility $defaultVisibility = Visibility::Private,
    ) {}

    // ── Write Operations ─────────────────────────────────────────

    public function put(string $path, string $contents, array $options = []): bool
    {
        $path = $this->normalizePath($path);
        $this->files[$path]        = $contents;
        $this->visibilities[$path] = $options['visibility'] ?? $this->defaultVisibility;
        $this->timestamps[$path]   = time();

        return true;
    }

    public function putStream(string $path, mixed $stream, array $options = []): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        $contents = stream_get_contents($stream);

        return $contents !== false && $this->put($path, $contents, $options);
    }

    public function append(string $path, string $contents): bool
    {
        $path = $this->normalizePath($path);
        $this->files[$path] = ($this->files[$path] ?? '') . $contents;
        $this->timestamps[$path] = time();

        return true;
    }

    public function prepend(string $path, string $contents): bool
    {
        $path = $this->normalizePath($path);
        $this->files[$path] = $contents . ($this->files[$path] ?? '');
        $this->timestamps[$path] = time();

        return true;
    }

    // ── Read Operations ──────────────────────────────────────────

    public function get(string $path): ?string
    {
        return $this->files[$this->normalizePath($path)] ?? null;
    }

    public function getStream(string $path): mixed
    {
        $contents = $this->get($path);

        if ($contents === null) {
            return null;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    // ── Delete Operations ────────────────────────────────────────

    public function delete(string $path): bool
    {
        $path = $this->normalizePath($path);
        unset($this->files[$path], $this->visibilities[$path], $this->timestamps[$path]);

        return true;
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function exists(string $path): bool
    {
        return isset($this->files[$this->normalizePath($path)]);
    }

    public function size(string $path): ?int
    {
        $contents = $this->get($path);

        return $contents !== null ? strlen($contents) : null;
    }

    public function mimeType(string $path): ?string
    {
        $contents = $this->get($path);

        if ($contents === null) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->buffer($contents) ?: null;
    }

    public function lastModified(string $path): ?int
    {
        return $this->timestamps[$this->normalizePath($path)] ?? null;
    }

    public function checksum(string $path, string $algo = 'sha256'): ?string
    {
        $contents = $this->get($path);

        return $contents !== null ? hash($algo, $contents) : null;
    }

    // ── Visibility ───────────────────────────────────────────────

    public function visibility(string $path): ?Visibility
    {
        return $this->visibilities[$this->normalizePath($path)] ?? null;
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $path = $this->normalizePath($path);

        if (!isset($this->files[$path])) {
            throw new FileNotFoundException($path);
        }

        $this->visibilities[$path] = $visibility;
    }

    // ── Copy / Move ──────────────────────────────────────────────

    public function copy(string $source, string $destination): bool
    {
        $source = $this->normalizePath($source);
        $destination = $this->normalizePath($destination);

        if (!isset($this->files[$source])) {
            throw new FileNotFoundException($source);
        }

        $this->files[$destination]        = $this->files[$source];
        $this->visibilities[$destination]  = $this->visibilities[$source] ?? $this->defaultVisibility;
        $this->timestamps[$destination]   = time();

        return true;
    }

    public function move(string $source, string $destination): bool
    {
        if ($this->copy($source, $destination)) {
            return $this->delete($source);
        }

        return false;
    }

    // ── Directory Operations ─────────────────────────────────────

    public function url(string $path): string
    {
        return '/memory/' . ltrim($this->normalizePath($path), '/');
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = $this->normalizePath($directory);
        $prefix    = $directory !== '' ? $directory . '/' : '';
        $results   = [];

        foreach (array_keys($this->files) as $path) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }

            $relative = $prefix !== '' ? substr($path, strlen($prefix)) : $path;

            if (!$recursive && str_contains($relative, '/')) {
                continue;
            }

            $results[] = $path;
        }

        sort($results);

        return $results;
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        $directory = $this->normalizePath($directory);
        $prefix    = $directory !== '' ? $directory . '/' : '';
        $dirs      = [];

        foreach (array_keys($this->files) as $path) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }

            $relative = $prefix !== '' ? substr($path, strlen($prefix)) : $path;
            $parts    = explode('/', $relative);

            if (count($parts) > 1) {
                $dir = $prefix . $parts[0];

                if (!in_array($dir, $dirs, true)) {
                    $dirs[] = $dir;
                }
            }
        }

        sort($dirs);

        return $dirs;
    }

    public function makeDirectory(string $path): bool
    {
        return true; // No-op in memory
    }

    public function deleteDirectory(string $path): bool
    {
        $path = $this->normalizePath($path);
        $prefix = $path . '/';

        foreach (array_keys($this->files) as $filePath) {
            if (str_starts_with($filePath, $prefix)) {
                $this->delete($filePath);
            }
        }

        return true;
    }

    // ── Driver Identity ──────────────────────────────────────────

    public function getDriver(): string
    {
        return 'memory';
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** Normalize a path: trim leading/trailing slashes, collapse doubles. */
    private function normalizePath(string $path): string
    {
        return trim(preg_replace('#/+#', '/', $path) ?? $path, '/');
    }

    /** Get total number of stored files (useful in tests). */
    public int $fileCount {
        get => count($this->files);
    }

    /** Get total bytes stored (useful in tests). */
    public int $totalBytes {
        get => array_sum(array_map('strlen', $this->files));
    }
}
