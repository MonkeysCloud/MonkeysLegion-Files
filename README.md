# MonkeysLegion Files

Production-ready file storage and upload management for the **MonkeysLegion** framework ecosystem. Built for high-traffic sites handling millions of files.

[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

## Features

- 🚀 **Chunked Uploads** - Resume-capable multipart uploads for large files
- ☁️ **Multi-Storage** - Local, S3, MinIO, DigitalOcean Spaces, Backblaze B2, Google Cloud Storage, Firebase
- 🖼️ **Image Processing** - Thumbnails, optimization, watermarks, format conversion
- 🔒 **Security** - Signed URLs, rate limiting, virus scanning
- 📊 **Database Tracking** - Full file metadata with soft deletes
- 🧹 **Garbage Collection** - Automatic cleanup of orphaned files
- 🌐 **CDN Integration** - CloudFront-style signed URLs
- ⚡ **Async Jobs** - Queue-based processing for heavy operations

## Installation

```bash
composer require monkeyscloud/monkeyslegion-files
```

This package requires the MonkeysLegion ecosystem:
- **MonkeysLegion-Mlc** - Configuration management (auto-included)
- **MonkeysLegion-Cache** - Caching and rate limiting (auto-included)
- **MonkeysLegion-Database** - Database tracking (auto-included)

### Optional Dependencies

```bash
# For S3-compatible storage (AWS, MinIO, DigitalOcean, Backblaze)
composer require aws/aws-sdk-php

# For Google Cloud Storage / Firebase Storage
composer require google/cloud-storage

# For image processing
# Install ext-gd or ext-imagick via your system package manager
```

## Quick Start

```php
use MonkeysLegion\Files\FilesServiceProvider;
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Database\Factory\ConnectionFactory;

// Setup with MonkeysLegion ecosystem
$cacheManager = new CacheManager(require 'config/cache.php');
$dbConnection = ConnectionFactory::create(require 'config/database.php');

$provider = new FilesServiceProvider(
    container: $container,
    cacheManager: $cacheManager,
    dbConnection: $dbConnection,
);
$provider->register();

// Now use the file manager
$files = $container->get(FilesManager::class);

// Store a file
$path = ml_files_put($_FILES['upload']['tmp_name']);

// Store content
$path = ml_files_put_string("Hello World", "text/plain");

// Get file contents
$contents = ml_files_get($path);

// Generate signed URL (valid for 10 minutes)
$url = ml_files_sign_url("/files/{$path}", 600);

// Delete a file
ml_files_delete($path);
```

## MonkeysLegion Ecosystem Integration

This package integrates seamlessly with the MonkeysLegion framework ecosystem:

### Configuration (MonkeysLegion-Mlc)

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parser;

$loader = new Loader(new Parser(), 'config');
$config = $loader->loadOne('files');

// Type-safe access
$maxSize = $config->getInt('files.upload.max_size', 20971520);
$allowedMimes = $config->getArray('files.upload.allowed_mimes', []);
```

### Caching (MonkeysLegion-Cache)

```php
use MonkeysLegion\Cache\CacheManager;

$cacheManager = new CacheManager([
    'default' => 'redis',
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'prefix' => 'ml_files_',
        ],
    ],
]);

// Rate limiting uses the cache
$rateLimiter = new UploadRateLimiter($cacheManager);
```

### Database (MonkeysLegion-Database)

```php
use MonkeysLegion\Database\Factory\ConnectionFactory;

$connection = ConnectionFactory::create([
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'dsn' => 'mysql:host=localhost;dbname=myapp',
            'username' => 'root',
            'password' => 'secret',
        ],
    ],
]);

// File repository uses the connection
$repository = new FileRepository($connection);
```

## Configuration

The package supports both PHP array and MLC configuration formats.

### MLC Format (Recommended)

Create `config/files.mlc`:

```mlc
# MonkeysLegion Files Configuration

files.default = env("FILES_DISK", "local")

files.disks.local {
    driver = "local"
    root = env("FILES_LOCAL_ROOT", "storage/files")
    visibility = "private"
}

files.disks.s3 {
    driver = "s3"
    key = env("AWS_ACCESS_KEY_ID", "")
    secret = env("AWS_SECRET_ACCESS_KEY", "")
    region = env("AWS_DEFAULT_REGION", "us-east-1")
    bucket = env("AWS_BUCKET", "")
    visibility = "private"
}

files.disks.gcs {
    driver = "gcs"
    project_id = env("GOOGLE_CLOUD_PROJECT_ID", "")
    bucket = env("GOOGLE_CLOUD_STORAGE_BUCKET", "")
    key_file_path = env("GOOGLE_CLOUD_KEY_FILE", "")
    visibility = "private"
}

files.upload {
    max_size = env("UPLOAD_MAX_BYTES", 20971520)
    chunk_size = 5242880
    allowed_mimes = ["image/jpeg", "image/png", "application/pdf"]
}

files.rate_limiting {
    enabled = true
    uploads_per_minute = 10
    bytes_per_hour = 104857600
    concurrent_uploads = 3
}

files.database {
    enabled = env("DATABASE_TRACKING_ENABLED", true)
}
```

### PHP Array Format

Create `config/files.php`:

```php
<?php
return [
    'default' => env('FILES_DISK', 'local'),
    
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('files'),
            'url' => env('FILES_PUBLIC_URL'),
            'visibility' => 'private',
        ],
        
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'visibility' => 'private',
        ],
    ],
    
    'upload' => [
        'max_size' => env('UPLOAD_MAX_BYTES', 20 * 1024 * 1024),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'chunk_size' => 5 * 1024 * 1024, // 5MB
    ],
    
    'security' => [
        'signing_key' => env('FILES_SIGNING_KEY'),
    ],
];
```

### Environment Variables

```env
# Storage
FILES_DISK=local
FILES_PUBLIC_URL=https://cdn.example.com
FILES_SIGNING_KEY=your-secret-key-here

# Upload limits
UPLOAD_MAX_BYTES=20971520
UPLOAD_MIME_ALLOW=image/jpeg,image/png,image/webp,application/pdf

# AWS S3 (optional)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket
AWS_ENDPOINT=

# Rate limiting
RATE_LIMIT_UPLOADS_PER_MINUTE=10
RATE_LIMIT_BYTES_PER_HOUR=104857600
RATE_LIMIT_CONCURRENT=3
```

## Usage

### Basic File Operations

```php
use MonkeysLegion\Files\FilesManager;

$files = new FilesManager($storage, $config);

// Store from various sources
$path = $files->put('/path/to/local/file.jpg');
$path = $files->put($_FILES['upload']);
$path = $files->putString($contents, 'text/plain');
$path = $files->putStream($stream, 'application/pdf');

// Read files
$contents = $files->get($path);
$stream = $files->getStream($path);

// Check existence
if ($files->exists($path)) {
    $size = $files->size($path);
    $mime = $files->mimeType($path);
}

// Move and copy
$files->copy($from, $to);
$files->move($from, $to);

// Delete
$files->delete($path);
```

### Chunked Uploads

Handle large files with resume capability:

```php
use MonkeysLegion\Files\Upload\ChunkedUploadManager;

$chunked = new ChunkedUploadManager($storage, $tempDir, $cache);

// 1. Initialize upload session
$uploadId = $chunked->initiate('large-video.mp4', $totalSize, 'video/mp4');

// 2. Upload chunks (from client)
foreach ($chunks as $index => $chunk) {
    $chunked->uploadChunk($uploadId, $index, $chunk['data'], $chunk['size']);
}

// 3. Complete and get final path
$finalPath = $chunked->complete($uploadId);

// Check progress anytime
$progress = $chunked->getProgress($uploadId);
// ['uploaded_chunks' => 5, 'total_chunks' => 10, 'percent' => 50, ...]

// Abort if needed
$chunked->abort($uploadId);
```

### Image Processing

```php
use MonkeysLegion\Files\Image\ImageProcessor;

$processor = new ImageProcessor(driver: 'gd', quality: 85);

// Create thumbnail
$thumbPath = $processor->thumbnail($path, 300, 300, 'cover');

// Optimize for web
$optimized = $processor->optimize($path, quality: 80);

// Convert format
$webp = $processor->convert($path, 'webp');

// Add watermark
$watermarked = $processor->watermark($path, $watermarkPath, 'bottom-right', 50);

// Batch conversions
$conversions = $processor->processConversions($path, [
    'thumb' => ['width' => 150, 'height' => 150, 'fit' => 'cover'],
    'medium' => ['width' => 800, 'height' => 600, 'fit' => 'contain'],
    'webp' => ['format' => 'webp', 'quality' => 80],
]);
```

### S3 Storage

```php
use MonkeysLegion\Files\Storage\S3Storage;

$s3 = new S3Storage(
    bucket: 'my-bucket',
    region: 'us-east-1',
    accessKey: $key,
    secretKey: $secret,
);

// Pre-signed upload URL (direct browser upload)
$presignedUrl = $s3->getUploadUrl('uploads/file.jpg', 'image/jpeg', 3600);

// Pre-signed download URL
$downloadUrl = $s3->getTemporaryUrl('private/doc.pdf', 3600);

// Multipart upload for very large files
$uploadId = $s3->initiateMultipartUpload('large-file.zip');
// ... upload parts ...
$s3->completeMultipartUpload('large-file.zip', $uploadId, $parts);
```

### Google Cloud Storage

```php
use MonkeysLegion\Files\Storage\GoogleCloudStorage;

// Using service account key file
$gcs = new GoogleCloudStorage(
    bucketName: 'my-bucket',
    projectId: 'my-project',
    keyFilePath: '/path/to/service-account.json',
);

// Or using key array (for environments where file access is limited)
$gcs = new GoogleCloudStorage(
    bucketName: 'my-bucket',
    projectId: 'my-project',
    keyFile: json_decode(file_get_contents('/path/to/key.json'), true),
);

// Basic operations work the same as other drivers
$gcs->put('path/to/file.txt', 'Hello World');
$content = $gcs->get('path/to/file.txt');

// Signed URL for private downloads
$signedUrl = $gcs->temporaryUrl('private/doc.pdf', new DateTime('+1 hour'));

// Direct browser upload with signed URL
$uploadUrl = $gcs->getUploadUrl('uploads/file.jpg', 'image/jpeg', 3600);

// Resumable upload for large files
$resumeUri = $gcs->initiateResumableUpload(
    path: 'large/video.mp4',
    contentType: 'video/mp4',
    contentLength: $fileSize,
);

// Compose multiple objects into one (useful for chunked uploads)
$gcs->compose(['chunk1.part', 'chunk2.part', 'chunk3.part'], 'complete-file.zip');

// Set visibility
$gcs->setVisibility('path/to/file.txt', 'public');
```

### Firebase Storage

Firebase Storage uses Google Cloud Storage as its backend:

```php
use MonkeysLegion\Files\Storage\GoogleCloudStorage;

$firebase = new GoogleCloudStorage(
    bucketName: 'my-project.appspot.com', // Firebase bucket format
    projectId: 'my-project',
    keyFilePath: '/path/to/firebase-adminsdk.json',
);

// Works exactly like GCS
$firebase->put('users/avatar.jpg', $imageData);
```

### Rate Limiting

```php
use MonkeysLegion\Files\RateLimit\UploadRateLimiter;

$limiter = new UploadRateLimiter(
    cache: $cache,
    maxUploadsPerMinute: 10,
    maxBytesPerHour: 100 * 1024 * 1024,
    maxConcurrentUploads: 3,
);

$userId = auth()->id();

// Check before upload
try {
    $limiter->check($userId);
} catch (RateLimitException $e) {
    return response()->json([
        'error' => $e->getMessage(),
        'retry_after' => $e->getRetryAfter(),
    ], 429);
}

// Track upload
$limiter->startUpload($userId);
try {
    // ... process upload ...
    $limiter->recordUpload($userId, $fileSize);
} finally {
    $limiter->endUpload($userId);
}

// Get status
$status = $limiter->getStatus($userId);
// ['uploads' => [...], 'bandwidth' => [...], 'concurrent' => [...]]
```

### Signed URLs

```php
// Generate signed URL
$url = ml_files_sign_url('/files/private/document.pdf', ttl: 600);
// Result: /files/private/document.pdf?expires=1699999999&signature=abc123

// Verify signed URL
if (ml_files_verify_signed_url($requestUrl)) {
    // Valid - serve the file
} else {
    // Invalid or expired
}
```

### CDN Integration

```php
use MonkeysLegion\Files\Cdn\CdnUrlGenerator;

$cdn = new CdnUrlGenerator(
    baseUrl: 'https://cdn.example.com',
    signingKey: $key,
);

// Public CDN URL
$url = $cdn->url('images/photo.jpg');
// https://cdn.example.com/images/photo.jpg

// Signed CDN URL
$signedUrl = $cdn->signedUrl('private/doc.pdf', expiry: 3600);

// Purge cache
$cdn->purge(['images/photo.jpg', 'images/banner.jpg']);
```

### Database Tracking

```php
use MonkeysLegion\Files\Repository\FileRepository;

$repo = new FileRepository($connection);

// Files are tracked automatically when using FilesManager
$record = $files->put($uploadedFile);

// Query files
$file = $repo->findByPath($path);
$userFiles = $repo->findByOwner($userId);
$images = $repo->findByMimeType('image/%');

// Soft delete
$repo->softDelete($fileId);

// Restore
$repo->restore($fileId);

// Permanent delete (also removes from storage)
$repo->forceDelete($fileId);
```

### Garbage Collection

```php
use MonkeysLegion\Files\Maintenance\GarbageCollector;

$gc = new GarbageCollector($storage, $repository, $config);

// Clean up soft-deleted files older than retention period
$gc->cleanupDeletedFiles();

// Remove orphaned files (in storage but not in database)
$gc->cleanupOrphanedFiles();

// Clean incomplete chunked uploads
$gc->cleanupIncompleteUploads();

// Clean unused image conversions
$gc->cleanupUnusedConversions();

// Run all cleanup tasks
$gc->runAll();
```

### Virus Scanning

```php
use MonkeysLegion\Files\Security\ClamAvScanner;
use MonkeysLegion\Files\Security\HttpVirusScanner;

// ClamAV (local)
$scanner = new ClamAvScanner('/var/run/clamav/clamd.ctl');

// HTTP-based (cloud)
$scanner = new HttpVirusScanner('https://api.scanner.example.com', $apiKey);

$result = $scanner->scan($filePath);

if ($result->isClean()) {
    // Safe to store
} else {
    // Threat detected
    $threat = $result->getThreat();
    // Quarantine or reject
}
```

## Global Helper Functions

```php
// Core operations
ml_files_put($source, $path, $options);
ml_files_put_string($contents, $mimeType);
ml_files_put_stream($stream, $mimeType);
ml_files_get($path);
ml_files_read_stream($path);
ml_files_delete($path);
ml_files_exists($path);
ml_files_size($path);
ml_files_mime($path);
ml_files_url($path);
ml_files_temp_url($path, $ttl);
ml_files_copy($from, $to);
ml_files_move($from, $to);
ml_files_list($directory);

// Signed URLs
ml_files_sign_url($url, $ttl);
ml_files_verify_signed_url($url);

// Chunked uploads
ml_files_chunked_init($filename, $size, $mime);
ml_files_chunked_upload($uploadId, $index, $data, $size);
ml_files_chunked_complete($uploadId);
ml_files_chunked_abort($uploadId);
ml_files_chunked_progress($uploadId);

// Image processing
ml_files_image_thumbnail($path, $width, $height);
ml_files_image_optimize($path, $quality);
ml_files_image_convert($path, $format);
ml_files_image_watermark($path, $watermark, $position);

// Utilities
ml_files_human_size($bytes);
ml_files_extension($path);
ml_files_safe_filename($filename);
ml_files_generate_path($extension);
ml_files_is_image($mimeType);
ml_files_is_video($mimeType);
ml_files_is_audio($mimeType);
```

## Database Migrations

Run the migrations:

```bash
php ml migrate
```

Tables created:
- `ml_files` - File metadata and tracking
- `ml_file_conversions` - Image variants (thumbnails, etc.)
- `ml_chunked_uploads` - Incomplete upload tracking

## Cron Jobs

Set up garbage collection:

```bash
# Run every hour
0 * * * * php /path/to/app ml:files:gc
```

## Security Best Practices

1. **Store files outside webroot** - Prevent direct access
2. **Validate MIME types** - Don't trust client headers
3. **Use signed URLs** - For private file access
4. **Enable virus scanning** - For user uploads
5. **Set rate limits** - Prevent abuse
6. **Use HTTPS** - For all file transfers

## Architecture

```
MonkeysLegion\Files\
├── Contracts/              # Interfaces
│   ├── StorageInterface
│   └── ChunkedUploadInterface
├── Storage/                # Storage drivers
│   ├── LocalStorage
│   ├── S3Storage
│   └── GoogleCloudStorage
├── Upload/                 # Upload handling
│   └── ChunkedUploadManager
├── Image/                  # Image processing
│   └── ImageProcessor
├── Security/               # Security features
│   ├── VirusScanner
│   └── (ClamAv/Http)
├── RateLimit/              # Rate limiting
│   └── UploadRateLimiter
├── Cdn/                    # CDN integration
│   └── CdnUrlGenerator
├── Repository/             # Database layer
│   └── FileRepository
├── Entity/                 # Entities
│   └── FileRecord
├── Maintenance/            # Cleanup tasks
│   └── GarbageCollector
├── Job/                    # Queue jobs
│   └── (ProcessImage, Cleanup, etc.)
├── Exception/              # Custom exceptions
├── FilesManager.php        # Main facade
├── FilesServiceProvider.php
└── helpers.php             # Global functions
```

## Testing

```bash
# Run tests
vendor/bin/phpunit

# With coverage
vendor/bin/phpunit --coverage-html coverage

# Static analysis
vendor/bin/phpstan analyse src --level=8

# Code style
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Upgrading from v1.x

1. Update `composer.json` to require `^2.0`
2. Run `composer update`
3. Run database migrations
4. Update configuration file
5. Replace deprecated helper function calls

See [UPGRADE.md](UPGRADE.md) for detailed migration guide.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Part of the [MonkeysLegion](https://github.com/MonkeysCloud) framework ecosystem.
