<?php

use GuzzleHttp\Psr7\Utils as Psr7;
use MonkeysLegion\Files\Contracts\FileNamer;
use MonkeysLegion\Files\Contracts\FileStorage;
use MonkeysLegion\Files\DTO\FileMeta;
use MonkeysLegion\Files\Upload\UploadManager;

/**
 * These helpers assume your app has a DI container accessor:
 * - container(): returns the container
 * - config(): returns merged config array
 * - env(): fetches environment variables
 *
 * If names differ, adapt here.
 */

if (!function_exists('ml_files_storage')) {
    function ml_files_storage(): FileStorage {
        return container()->get(FileStorage::class);
    }
}

if (!function_exists('ml_files_namer')) {
    function ml_files_namer(): FileNamer {
        return container()->get(FileNamer::class);
    }
}

if (!function_exists('ml_upload_manager')) {
    function ml_upload_manager(): UploadManager {
        return container()->get(UploadManager::class);
    }
}

/**
 * Store a PSR-7 StreamInterface at an auto-generated path; returns FileMeta.
 */
if (!function_exists('ml_files_put')) {
    function ml_files_put(
        Psr\Http\Message\StreamInterface $stream,
        string $originalName,
        string $mime = 'application/octet-stream'
    ): FileMeta {
        $ctx = hash_init('sha256');
        $tmp = fopen('php://temp', 'w+b');
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            hash_update($ctx, $chunk);
            fwrite($tmp, $chunk);
        }
        $sha256 = hash_final($ctx);
        rewind($tmp);

        $namer = ml_files_namer();
        $path = $namer->path($originalName, $mime, $sha256);

        $storage = ml_files_storage();
        $url = $storage->put($path, Psr7::streamFor($tmp), ['mime' => $mime]);

        return new FileMeta(
            disk: $storage->name(),
            path: $path,
            url: $url,
            originalName: $originalName,
            mimeType: $mime,
            size: (int) fstat($tmp)['size'],
            sha256: $sha256
        );
    }
}

/** Convenience: store a raw string quickly. */
if (!function_exists('ml_files_put_string')) {
    function ml_files_put_string(string $data, string $mime = 'application/octet-stream', string $name = 'file.bin'): string {
        $stream = Psr7::streamFor($data);
        $meta = ml_files_put($stream, $name, $mime);
        return $meta->path;
    }
}

/** Convenience: store file from local filesystem path. */
if (!function_exists('ml_files_put_path')) {
    function ml_files_put_path(string $localPath, ?string $mime = null, ?string $name = null): string {
        $mime ??= (function(string $p){
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = finfo_file($f, $p) ?: 'application/octet-stream';
            finfo_close($f);
            return $m;
        })($localPath);
        $name ??= basename($localPath);
        $stream = Psr7::streamFor(fopen($localPath, 'rb'));
        $meta = ml_files_put($stream, $name, $mime);
        return $meta->path;
    }
}

if (!function_exists('ml_files_delete')) {
    function ml_files_delete(string $path): void {
        ml_files_storage()->delete($path);
    }
}

if (!function_exists('ml_files_exists')) {
    function ml_files_exists(string $path): bool {
        return ml_files_storage()->exists($path);
    }
}

if (!function_exists('ml_files_read_stream')) {
    function ml_files_read_stream(string $path): Psr\Http\Message\StreamInterface {
        return ml_files_storage()->read($path);
    }
}

if (!function_exists('ml_files_url')) {
    function ml_files_url(string $path): ?string {
        // For local private disks, this will be null. For public disks/CDN it returns URL.
        // You can build your own serving URL using signed URLs below.
        $storage = ml_files_storage();
        if (method_exists($storage, 'publicUrl')) {
            return $storage->publicUrl($path); // optional
        }
        // Fallback: if disk returns null on put, try building from config public_base_url
        $cfg = config()['files'] ?? [];
        $disk = $cfg['default_disk'] ?? 'local';
        $d = ($cfg['disks'] ?? [])[$disk] ?? [];
        if (!empty($d['public_base_url'])) {
            return rtrim($d['public_base_url'], '/').'/'.ltrim($path, '/');
        }
        return null;
    }
}

/**
 * Simple signed URL helpers (HMAC-SHA256).
 * Example usage:
 *   $signed = ml_files_sign_url('/files/'.$path, 600);
 *   // verify later:
 *   if (ml_files_verify_signature($signed)) { ... }
 */
if (!function_exists('ml_files_sign_url')) {
    function ml_files_sign_url(string $relativePath, int $ttlSeconds): string {
        $cfg = config()['files'] ?? [];
        $key = $cfg['signing_key'] ?? '';
        if ($key === '') {
            throw new \RuntimeException('FILES_SIGNING_KEY (files.signing_key) not configured.');
        }
        $exp = time() + $ttlSeconds;
        $payload = $relativePath.'|'.$exp;
        $sig = hash_hmac('sha256', $payload, $key);
        return $relativePath.(str_contains($relativePath, '?') ? '&' : '?').'exp='.$exp.'&sig='.$sig;
    }
}

if (!function_exists('ml_files_verify_signature')) {
    function ml_files_verify_signature(string $url): bool {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $exp = (int)($q['exp'] ?? 0);
        $sig = (string)($q['sig'] ?? '');
        if ($exp < time() || $sig === '') return false;

        $path = ($parts['path'] ?? '');
        $payload = $path.'|'.$exp;

        $cfg = config()['files'] ?? [];
        $key = $cfg['signing_key'] ?? '';
        if ($key === '') return false;

        $calc = hash_hmac('sha256', $payload, $key);
        return hash_equals($calc, $sig);
    }
}
