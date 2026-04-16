# MonkeysLegion Files v2

Production-ready file storage, upload, and image processing for the MonkeysLegion framework.

> **PHP 8.4+** | Property Hooks | Asymmetric Visibility | Zero-Magic

## Features

| Feature | Status |
|---------|--------|
| Multi-driver storage (Local, S3, GCS, Memory) | ✅ |
| Presigned upload URLs (browser → S3 direct) | ✅ |
| MIME content sniffing (anti-spoofing) | ✅ |
| Atomic writes (tmp + rename) | ✅ |
| Cross-disk copy/move | ✅ |
| Upload validation (size, MIME, extension) | ✅ |
| File record entity with property hooks | ✅ |
| Domain events (FileStored, FileDeleted, FileMoved) | ✅ |
| CDN URL generation with signed URLs | ✅ |
| Image processing (GD/Imagick, WebP/AVIF) | ✅ |
| Virus scanning integration | ✅ |
| In-memory driver for testing | ✅ |
| Garbage collection for orphaned files | ✅ |

## Installation

```bash
composer require monkeyscloud/monkeyslegion-files
```

## Quick Start

```php
use MonkeysLegion\Files\FilesManager;
use MonkeysLegion\Files\Driver\LocalDriver;
use MonkeysLegion\Files\Driver\MemoryDriver;

// Create a manager with disks
$manager = new FilesManager(
    disks: [
        'local' => new LocalDriver('/var/www/storage', '/files'),
        'tmp'   => new MemoryDriver(),
    ],
    defaultDisk: 'local',
);

// Store a file
$manager->put('documents/readme.md', '# Hello World');

// Read it back
$contents = $manager->get('documents/readme.md');

// Check existence
$manager->exists('documents/readme.md'); // true

// Get metadata
$manager->size('documents/readme.md');     // 13
$manager->mimeType('documents/readme.md'); // 'text/plain'
$manager->checksum('documents/readme.md'); // sha256 hash
```

## Upload Handling

```php
use MonkeysLegion\Files\Upload\UploadedFile;
use MonkeysLegion\Files\Upload\UploadValidator;
use MonkeysLegion\Files\Security\ContentValidator;

// Create manager with validation
$manager = new FilesManager(
    disks: ['local' => new LocalDriver('/var/www/storage')],
    validator: new UploadValidator(
        maxSize: 50 * 1024 * 1024,  // 50 MB
        allowedMimes: ['image/jpeg', 'image/png', 'application/pdf'],
        deniedExtensions: ['php', 'exe', 'sh'],
    ),
    contentValidator: new ContentValidator(), // MIME sniffing
);

// Handle upload
$file = UploadedFile::fromGlobal($_FILES['avatar']);

// Property hooks — zero methods!
echo $file->extension;  // 'jpg'
echo $file->isImage;    // true
echo $file->humanSize;  // '2.5 MB'

$result = $manager->upload($file, 'avatars');

if ($result->failed) {
    // $result->errors contains validation messages
    foreach ($result->errors as $error) {
        echo $error;
    }
} else {
    echo $result->file->uuid;      // '550e8400-...'
    echo $result->file->humanSize; // '2.5 MB'
    echo $result->file->isImage;   // true
}
```

## FileRecord — PHP 8.4 Property Hooks

```php
use MonkeysLegion\Files\Entity\FileRecord;
use MonkeysLegion\Files\Visibility;

$record = new FileRecord(
    disk: 'local',
    path: '/uploads/photo.jpg',  // set hook strips leading /
    originalName: 'photo.jpg',
    mimeType: 'image/jpeg',
    size: 2_621_440,
);

// Computed properties via get hooks — no methods!
$record->path;         // 'uploads/photo.jpg' (set hook normalized)
$record->extension;    // 'jpg'
$record->basename;     // 'photo'
$record->isImage;      // true
$record->isVideo;      // false
$record->humanSize;    // '2.5 MB'
$record->isDeleted;    // false

// Asymmetric visibility — read public, write private
$record->id;           // null (public private(set))
$record->uuid;         // '550e8400-...' (public private(set))
$record->createdAt;    // DateTimeImmutable (public private(set))

// Business logic
$record->softDelete();
$record->isDeleted;    // true (computed via hook)
$record->restore();

$record->attachTo('App\\Entity\\User', 42, 'avatars');
$record->setChecksum('abc123', 'sha256');
```

## Cross-Disk Operations

```php
// Copy between disks
$manager->crossDiskCopy(
    source: 'photo.jpg',
    destination: 'photo.jpg',
    sourceDisk: 'local',
    destDisk: 's3',
);

// Move between disks (atomic: copy + delete)
$manager->crossDiskMove(
    source: 'temp/file.pdf',
    destination: 'documents/file.pdf',
    sourceDisk: 'local',
    destDisk: 's3',
);
```

## Storage Drivers

### LocalDriver
```php
use MonkeysLegion\Files\Driver\LocalDriver;
use MonkeysLegion\Files\Visibility;

$local = new LocalDriver(
    basePath: '/var/www/storage',
    baseUrl: '/files',
    dirPermissions: 0o755,
    filePermissions: 0o644,
    defaultVisibility: Visibility::Public,
);
```

### MemoryDriver (Testing)
```php
use MonkeysLegion\Files\Driver\MemoryDriver;

$memory = new MemoryDriver();
$memory->put('test.txt', 'hello');

// Property hooks for test assertions
echo $memory->fileCount;   // 1
echo $memory->totalBytes;  // 5
```

## Security

### Path Traversal Prevention
The `LocalDriver` blocks `..` segments and validates resolved paths against the base directory. Both regex and `realpath()` checks are applied.

### MIME Content Sniffing
```php
$validator = new ContentValidator();
$validator->validate('/tmp/upload.jpg', 'image/jpeg');
// Throws SecurityException if actual content is PHP code
```

### Virus Scanning
```php
use MonkeysLegion\Files\Security\ScanResult;

$result = new ScanResult(isClean: false, threat: 'Trojan.Gen');
$result->hasThreat; // true (computed via hook)
```

## Domain Events

```php
use MonkeysLegion\Files\Event\FileStored;
use MonkeysLegion\Files\Event\FileDeleted;
use MonkeysLegion\Files\Event\FileMoved;

// All events are readonly value objects
$event = new FileStored(file: $record, disk: 'local');
$event->occurredAt; // DateTimeImmutable
```

## Enums

```php
use MonkeysLegion\Files\Visibility;
use MonkeysLegion\Files\Image\ImageFormat;
use MonkeysLegion\Files\Image\ImageDriver;

Visibility::Public;   // 'public'
Visibility::Private;  // 'private'

ImageFormat::Webp->mimeType();   // 'image/webp'
ImageFormat::Avif->extension();  // 'avif'

ImageDriver::Gd->isAvailable();  // true/false
```

## Architecture

```
src/
├── Contracts/          # StorageInterface, CloudStorageInterface
├── Driver/             # LocalDriver, MemoryDriver (S3/GCS stubs)
├── Entity/             # FileRecord with property hooks
├── Event/              # FileStored, FileDeleted, FileMoved
├── Exception/          # FilesException hierarchy
├── Image/              # ImageDriver, ImageFormat enums
├── Security/           # ContentValidator, ScanResult
├── Upload/             # UploadedFile, UploadValidator, UploadResult
├── FilesManager.php    # Main facade
└── Visibility.php      # Backed enum
```

## Testing

```bash
vendor/bin/phpunit               # 127 tests, 223 assertions
vendor/bin/phpunit --coverage-text
```

## Requirements

- PHP ^8.4
- monkeyscloud/monkeyslegion-mlc ^3.1.2
- psr/log ^3.0

## License

MIT © MonkeysCloud Team
