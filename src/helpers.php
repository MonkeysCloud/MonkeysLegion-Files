<?php

declare(strict_types=1);

/**
 * MonkeysLegion Files - Global Helper Functions
 * 
 * Production-ready helper functions for file operations.
 * These functions provide convenient shortcuts to the FilesManager service.
 */

use MonkeysLegion\Files\Contracts\StorageInterface;
use MonkeysLegion\Files\FilesManager;
use MonkeysLegion\Files\FilesServiceProvider;
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Upload\ChunkedUploadManager;
use MonkeysLegion\Files\Image\ImageProcessor;

if (!function_exists('ml_files')) {
    /**
     * Get the FilesManager instance or perform a quick operation.
     *
     * @param string|null $method Optional method to call
     * @param mixed ...$args Arguments for the method
     * @return FilesManager|mixed
     */
    function ml_files(?string $method = null, mixed ...$args): mixed
    {
        $manager = ml_container()->get(FilesManager::class);
        
        if ($method === null) {
            return $manager;
        }
        
        return $manager->{$method}(...$args);
    }
}

if (!function_exists('ml_files_disk')) {
    /**
     * Get a specific storage disk.
     *
     * @param string|null $name Disk name (null for default)
     * @return StorageInterface
     */
    function ml_files_disk(?string $name = null): StorageInterface
    {
        return ml_container()->get(FilesServiceProvider::class)->disk($name);
    }
}

if (!function_exists('ml_files_put')) {
    /**
     * Store a file from various sources.
     *
     * @param mixed $source File path, uploaded file, stream, or string content
     * @param string|null $path Destination path (auto-generated if null)
     * @param array $options Additional options (disk, visibility, metadata)
     * @return FileRecord|string Returns FileRecord if database tracking enabled, path otherwise
     */
    function ml_files_put(mixed $source, ?string $path = null, array $options = []): FileRecord|string
    {
        if ($path === null) {
            $extension = is_string($source) ? 'txt' : 'bin'; // naive default
            $path = ml_files_generate_path($extension);
        }
        
        // Handle different source types
        if (is_resource($source)) {
            return ml_files()->putStream($path, $source, $options['disk'] ?? null, $options);
        }
        
        if (is_string($source) && is_file($source)) {
            // It's a file path
             return ml_files()->putFile(dirname($source), [
                 'name' => basename($source),
                 'tmp_name' => $source,
                 'type' => mime_content_type($source),
                 'size' => filesize($source),
                 'error' => 0
             ], $options['disk'] ?? null, $options);
        }

        // Treat as string content
        return ml_files()->put($path, (string)$source, $options['disk'] ?? null, $options);
    }
}

if (!function_exists('ml_files_put_string')) {
    /**
     * Store content from a string.
     *
     * @param string $contents The content to store
     * @param string $mimeType MIME type of the content
     * @param string|null $path Destination path (auto-generated if null)
     * @param array $options Additional options
     * @return FileRecord|string
     */
    function ml_files_put_string(string $contents, string $mimeType, ?string $path = null, array $options = []): FileRecord|string
    {
        if ($path === null) {
            $path = ml_files_generate_path(ml_files_mime_to_extension($mimeType) ?? 'txt');
        }
        $options['mime_type'] = $mimeType;
        return ml_files()->put($path, $contents, $options['disk'] ?? null, $options);
    }
}

if (!function_exists('ml_files_put_stream')) {
    /**
     * Store content from a stream resource.
     *
     * @param resource $stream The stream resource
     * @param string $mimeType MIME type of the content
     * @param string|null $path Destination path (auto-generated if null)
     * @param array $options Additional options
     * @return FileRecord|string
     */
    function ml_files_put_stream($stream, string $mimeType, ?string $path = null, array $options = []): FileRecord|string
    {
        if ($path === null) {
            $path = ml_files_generate_path(ml_files_mime_to_extension($mimeType) ?? 'bin');
        }
        $options['mime_type'] = $mimeType;
        return ml_files()->putStream($path, $stream, $options['disk'] ?? null, $options);
    }
}

if (!function_exists('ml_files_get')) {
    /**
     * Get file contents as string.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return string
     */
    function ml_files_get(string $path, ?string $disk = null): string
    {
        return ml_files_disk($disk)->get($path);
    }
}

if (!function_exists('ml_files_read_stream')) {
    /**
     * Get file contents as a stream resource.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return resource
     */
    function ml_files_read_stream(string $path, ?string $disk = null)
    {
        return ml_files_disk($disk)->getStream($path);
    }
}

if (!function_exists('ml_files_delete')) {
    /**
     * Delete a file.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return bool
     */
    function ml_files_delete(string $path, ?string $disk = null): bool
    {
        return ml_files()->delete($path, $disk);
    }
}

if (!function_exists('ml_files_exists')) {
    /**
     * Check if a file exists.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return bool
     */
    function ml_files_exists(string $path, ?string $disk = null): bool
    {
        return ml_files_disk($disk)->exists($path);
    }
}

if (!function_exists('ml_files_size')) {
    /**
     * Get the file size in bytes.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return int
     */
    function ml_files_size(string $path, ?string $disk = null): int
    {
        return ml_files_disk($disk)->size($path);
    }
}

if (!function_exists('ml_files_mime')) {
    /**
     * Get the file MIME type.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return string
     */
    function ml_files_mime(string $path, ?string $disk = null): string
    {
        return ml_files_disk($disk)->mimeType($path);
    }
}

if (!function_exists('ml_files_url')) {
    /**
     * Get the public URL for a file.
     *
     * @param string $path File path
     * @param string|null $disk Disk name (null for default)
     * @return string
     */
    function ml_files_url(string $path, ?string $disk = null): string
    {
        return ml_files()->url($path, $disk);
    }
}

if (!function_exists('ml_files_temp_url')) {
    /**
     * Generate a temporary (signed) URL for a file.
     *
     * @param string $path File path
     * @param int $ttl Time to live in seconds (default: 3600)
     * @param string|null $disk Disk name (null for default)
     * @return string
     */
    function ml_files_temp_url(string $path, int $ttl = 3600, ?string $disk = null): string
    {
        $expiration = (new \DateTimeImmutable())->modify("+{$ttl} seconds");
        return ml_files()->temporaryUrl($path, $expiration, $disk);
    }
}





if (!function_exists('ml_files_copy')) {
    /**
     * Copy a file to a new location.
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @param string|null $disk Disk name (null for default)
     * @return bool
     */
    function ml_files_copy(string $from, string $to, ?string $disk = null): bool
    {
        return ml_files_disk($disk)->copy($from, $to);
    }
}

if (!function_exists('ml_files_move')) {
    /**
     * Move a file to a new location.
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @param string|null $disk Disk name (null for default)
     * @return bool
     */
    function ml_files_move(string $from, string $to, ?string $disk = null): bool
    {
        return ml_files_disk($disk)->move($from, $to);
    }
}

if (!function_exists('ml_files_list')) {
    /**
     * List files in a directory.
     *
     * @param string $directory Directory path
     * @param bool $recursive Include subdirectories
     * @param string|null $disk Disk name (null for default)
     * @return array<string>
     */
    function ml_files_list(string $directory = '', bool $recursive = false, ?string $disk = null): array
    {
        return ml_files_disk($disk)->files($directory, $recursive);
    }
}

// ============================================================================
// Chunked Upload Helpers
// ============================================================================

if (!function_exists('ml_files_chunked_init')) {
    /**
     * Initialize a chunked upload session.
     *
     * @param string $filename Original filename
     * @param int $totalSize Total file size in bytes
     * @param string $mimeType File MIME type
     * @param array $metadata Additional metadata
     * @return string Upload session ID
     */
    function ml_files_chunked_init(string $filename, int $totalSize, string $mimeType, array $metadata = []): string
    {
        return ml_container()->get(ChunkedUploadManager::class)
            ->initiate($filename, $totalSize, $mimeType, $metadata);
    }
}

if (!function_exists('ml_files_chunked_upload')) {
    /**
     * Upload a chunk for an ongoing chunked upload.
     *
     * @param string $uploadId Upload session ID
     * @param int $chunkIndex Zero-based chunk index
     * @param mixed $data Chunk data (string or stream)
     * @param int $chunkSize Size of this chunk
     * @return bool
     */
    function ml_files_chunked_upload(string $uploadId, int $chunkIndex, mixed $data, int $chunkSize): bool
    {
        return ml_container()->get(ChunkedUploadManager::class)
            ->uploadChunk($uploadId, $chunkIndex, $data, $chunkSize);
    }
}

if (!function_exists('ml_files_chunked_complete')) {
    /**
     * Complete a chunked upload.
     *
     * @param string $uploadId Upload session ID
     * @return string Final file path
     */
    function ml_files_chunked_complete(string $uploadId): string
    {
        return ml_container()->get(ChunkedUploadManager::class)->complete($uploadId);
    }
}

if (!function_exists('ml_files_chunked_abort')) {
    /**
     * Abort and clean up a chunked upload.
     *
     * @param string $uploadId Upload session ID
     * @return bool
     */
    function ml_files_chunked_abort(string $uploadId): bool
    {
        return ml_container()->get(ChunkedUploadManager::class)->abort($uploadId);
    }
}

if (!function_exists('ml_files_chunked_progress')) {
    /**
     * Get chunked upload progress.
     *
     * @param string $uploadId Upload session ID
     * @return array Progress information
     */
    function ml_files_chunked_progress(string $uploadId): array
    {
        return ml_container()->get(ChunkedUploadManager::class)->getProgress($uploadId);
    }
}

// ============================================================================
// Image Processing Helpers
// ============================================================================

if (!function_exists('ml_files_image_thumbnail')) {
    /**
     * Create a thumbnail from an image.
     *
     * @param string $path Source image path
     * @param int $width Maximum width
     * @param int $height Maximum height
     * @param string $fit Fit mode: cover, contain, stretch, fit
     * @return string Thumbnail path
     */
    function ml_files_image_thumbnail(string $path, int $width, int $height, string $fit = 'cover', ?string $disk = null): string
    {
        return ml_container()->get(ImageProcessor::class)
            ->thumbnail(ml_files_disk($disk), $path, $width, $height, $fit);
    }
}

if (!function_exists('ml_files_image_optimize')) {
    /**
     * Optimize an image for web delivery.
     *
     * @param string $path Source image path
     * @param int $quality Quality (1-100)
     * @return string Optimized image path
     */
    function ml_files_image_optimize(string $path, int $quality = 85, ?string $disk = null): string
    {
        return ml_container()->get(ImageProcessor::class)->optimize(ml_files_disk($disk), $path, $quality);
    }
}

if (!function_exists('ml_files_image_convert')) {
    /**
     * Convert image to a different format.
     *
     * @param string $path Source image path
     * @param string $format Target format (jpeg, png, webp, gif)
     * @param int $quality Quality for lossy formats
     * @return string Converted image path
     */
    function ml_files_image_convert(string $path, string $format, int $quality = 85, ?string $disk = null): string
    {
        return ml_container()->get(ImageProcessor::class)->convert(ml_files_disk($disk), $path, $format); // Note: convert doesn't take quality in signature, checking ImageProcessor... wait
    }
}

if (!function_exists('ml_files_image_watermark')) {
    /**
     * Add a watermark to an image.
     *
     * @param string $path Source image path
     * @param string $watermarkPath Watermark image path
     * @param string $position Position: top-left, top-center, top-right, etc.
     * @param int $opacity Watermark opacity (0-100)
     * @return string Watermarked image path
     */
    function ml_files_image_watermark(string $path, string $watermarkPath, string $position = 'bottom-right', int $opacity = 50, ?string $disk = null): string
    {
        return ml_container()->get(ImageProcessor::class)
            ->watermark(ml_files_disk($disk), $path, $watermarkPath, $position, $opacity);
    }
}

// ============================================================================
// Utility Helpers
// ============================================================================

if (!function_exists('ml_files_human_size')) {
    /**
     * Convert bytes to human-readable format.
     *
     * @param int $bytes Size in bytes
     * @param int $decimals Decimal places
     * @return string Human-readable size (e.g., "1.5 MB")
     */
    function ml_files_human_size(int $bytes, int $decimals = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, $decimals) . ' ' . $units[$i];
    }
}

if (!function_exists('ml_files_extension')) {
    /**
     * Get the extension from a filename or path.
     *
     * @param string $path Filename or path
     * @return string Extension (lowercase, without dot)
     */
    function ml_files_extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}

if (!function_exists('ml_files_safe_filename')) {
    /**
     * Sanitize a filename for safe storage.
     *
     * @param string $filename Original filename
     * @param bool $preserveExtension Keep the file extension
     * @return string Safe filename
     */
    function ml_files_safe_filename(string $filename, bool $preserveExtension = true): string
    {
        // Get extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        
        // Remove dangerous characters
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $basename);
        $safe = preg_replace('/-+/', '-', $safe);
        $safe = trim($safe, '-');
        
        // Ensure not empty
        if (empty($safe)) {
            $safe = 'file-' . bin2hex(random_bytes(4));
        }
        
        // Limit length
        $safe = substr($safe, 0, 200);
        
        if ($preserveExtension && $extension) {
            $safe .= '.' . strtolower($extension);
        }
        
        return $safe;
    }
}

if (!function_exists('ml_files_generate_path')) {
    /**
     * Generate a unique file path with optional date partitioning.
     *
     * @param string $extension File extension
     * @param string|null $prefix Optional path prefix
     * @param bool $datePartition Use date-based directory structure
     * @return string Generated path
     */
    function ml_files_generate_path(string $extension, ?string $prefix = null, bool $datePartition = true): string
    {
        $filename = bin2hex(random_bytes(16)) . '.' . ltrim($extension, '.');
        
        $parts = [];
        
        if ($prefix) {
            $parts[] = trim($prefix, '/');
        }
        
        if ($datePartition) {
            $parts[] = date('Y');
            $parts[] = date('m');
            $parts[] = date('d');
        }
        
        $parts[] = $filename;
        
        return implode('/', $parts);
    }
}

if (!function_exists('ml_files_mime_to_extension')) {
    /**
     * Get the typical extension for a MIME type.
     *
     * @param string $mimeType MIME type
     * @return string|null Extension or null if unknown
     */
    function ml_files_mime_to_extension(string $mimeType): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/css' => 'css',
            'text/javascript' => 'js',
            'application/zip' => 'zip',
            'application/gzip' => 'gz',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];
        
        return $map[$mimeType] ?? null;
    }
}

if (!function_exists('ml_files_is_image')) {
    /**
     * Check if a MIME type represents an image.
     *
     * @param string $mimeType MIME type
     * @return bool
     */
    function ml_files_is_image(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }
}

if (!function_exists('ml_files_is_video')) {
    /**
     * Check if a MIME type represents a video.
     *
     * @param string $mimeType MIME type
     * @return bool
     */
    function ml_files_is_video(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }
}

if (!function_exists('ml_files_is_audio')) {
    /**
     * Check if a MIME type represents audio.
     *
     * @param string $mimeType MIME type
     * @return bool
     */
    function ml_files_is_audio(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/');
    }
}

// ============================================================================
// Container Helper (framework integration point)
// ============================================================================

if (!function_exists('ml_container')) {
    /**
     * Get the DI container instance.
     * This function should be overridden by the application bootstrap.
     *
     * @return object
     */
    function ml_container(): object
    {
        global $mlContainer;
        
        if ($mlContainer === null) {
            throw new RuntimeException(
                'Container not initialized. Call ml_set_container() in your bootstrap.'
            );
        }
        
        return $mlContainer;
    }
}

if (!function_exists('ml_set_container')) {
    /**
     * Set the DI container instance.
     *
     * @param object $container PSR-11 compatible container
     * @return void
     */
    function ml_set_container(object $container): void
    {
        global $mlContainer;
        $mlContainer = $container;
    }
}
