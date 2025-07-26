# MonkeysLegion Files

Framework-level file storage and upload helpers for the **MonkeysLegion** ecosystem.
This package intentionally ships **no controllers**. It exposes:
- Interfaces for storage backends
- Ready-to-use Local storage driver (S3/GCS optional stubs)
- `UploadManager` service
- **Global helper functions** `ml_files_*()` for quick usage in apps
- Config file and a lightweight ServiceProvider for DI wiring
- Optional signed-URL utilities for private downloads

## Install

```bash
composer require monkeyscloud/monkeyslegion-files
```

## Configure

Copy or publish `config/files.php`, then set env vars:

```
FILES_DISK=local
FILES_PUBLIC_URL=
UPLOAD_MAX_BYTES=20971520
UPLOAD_MIME_ALLOW=image/jpeg,image/png,image/webp,application/pdf
FILES_SIGNING_KEY=change-me-please
```

## Quick usage

```php
// store a string
$path = ml_files_put_string("hello world", "text/plain");

// get a stream
$stream = ml_files_read_stream($path);

// generate a signed URL (valid for 10 minutes)
$url = ml_files_sign_url("/files/{$path}", 600);
```

## Services

- `MonkeysLegion\Files\Contracts\FileStorage`
- `MonkeysLegion\Files\Contracts\FileNamer`
- `MonkeysLegion\Files\Upload\UploadManager`

## Security notes

- Place local storage **outside webroot**.
- Validate size and MIME types on input.
- Use `FILES_SIGNING_KEY` + `ml_files_sign_url()` for private links.

## Tests

```bash
vendor/bin/phpunit
```
