<?php

declare(strict_types=1);

/**
 * MonkeysLegion Files Configuration
 *
 * This configuration file defines all settings for the file storage system.
 * Copy this file to your project's config directory and customize as needed.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    |
    | The default disk to use for file storage operations. This should match
    | one of the disks defined in the 'disks' array below.
    |
    */
    'default' => env('FILES_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage Disks
    |--------------------------------------------------------------------------
    |
    | Define all available storage disks. Each disk can use a different driver
    | and configuration. Supported drivers: local, s3 (also works with MinIO,
    | DigitalOcean Spaces, Backblaze B2, etc.)
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => env('FILES_LOCAL_ROOT', storage_path('files')),
            'url' => env('FILES_LOCAL_URL'),
            'visibility' => 'private',
            'permissions' => [
                'file' => [
                    'public' => 0644,
                    'private' => 0600,
                ],
                'dir' => [
                    'public' => 0755,
                    'private' => 0700,
                ],
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'), // For S3-compatible services
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
        ],

        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY'),
            'secret' => env('MINIO_SECRET_KEY'),
            'region' => 'us-east-1',
            'bucket' => env('MINIO_BUCKET'),
            'endpoint' => env('MINIO_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
        ],

        'spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION', 'nyc3'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'visibility' => 'private',
        ],

        'gcs' => [
            'driver' => 'gcs',
            'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
            'bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET'),
            'key_file_path' => env('GOOGLE_CLOUD_KEY_FILE'), // Path to service account JSON
            'key_file' => env('GOOGLE_CLOUD_KEY_JSON') 
                ? json_decode(env('GOOGLE_CLOUD_KEY_JSON'), true) 
                : null, // Or JSON content directly
            'path_prefix' => env('GOOGLE_CLOUD_STORAGE_PATH_PREFIX', ''),
            'visibility' => 'private',
            'url' => env('GOOGLE_CLOUD_STORAGE_URL'), // Custom URL (CDN)
            'api_endpoint' => env('GOOGLE_CLOUD_STORAGE_API_ENDPOINT'), // For emulator
        ],

        'firebase' => [
            'driver' => 'gcs',
            'project_id' => env('FIREBASE_PROJECT_ID'),
            'bucket' => env('FIREBASE_STORAGE_BUCKET'), // Usually: project-id.appspot.com
            'key_file_path' => env('FIREBASE_CREDENTIALS'),
            'visibility' => 'private',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configure upload restrictions and behavior. These settings help prevent
    | abuse and ensure uploaded files meet your requirements.
    |
    */
    'upload' => [
        // Maximum file size in bytes (default: 20MB)
        'max_size' => env('UPLOAD_MAX_BYTES', 20 * 1024 * 1024),

        // Allowed MIME types (comma-separated in env)
        'allowed_mimes' => env('UPLOAD_MIME_ALLOW')
            ? explode(',', env('UPLOAD_MIME_ALLOW'))
            : [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/svg+xml',
                'application/pdf',
                'text/plain',
                'text/csv',
                'application/json',
                'application/zip',
                'application/x-gzip',
            ],

        // Blocked MIME types (security measure)
        'blocked_mimes' => [
            'application/x-php',
            'application/x-httpd-php',
            'application/x-sh',
            'application/x-csh',
            'text/x-php',
        ],

        // Chunk size for chunked uploads (default: 5MB)
        'chunk_size' => env('UPLOAD_CHUNK_SIZE', 5 * 1024 * 1024),

        // Temporary directory for chunked uploads
        'temp_dir' => env('UPLOAD_TEMP_DIR', sys_get_temp_dir() . '/ml_files_chunks'),

        // Upload session expiry in seconds (default: 24 hours)
        'session_expiry' => env('UPLOAD_SESSION_EXPIRY', 86400),

        // Verify file content matches declared MIME type
        'verify_mime' => env('UPLOAD_VERIFY_MIME', true),

        // Generate checksum for uploaded files
        'generate_checksum' => env('UPLOAD_GENERATE_CHECKSUM', true),

        // Checksum algorithm
        'checksum_algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    |
    | Configure image manipulation settings. These are used when creating
    | thumbnails, optimizing images, and applying transformations.
    |
    */
    'image' => [
        // Driver: 'gd' or 'imagick'
        'driver' => env('IMAGE_DRIVER', 'gd'),

        // Maximum dimensions (prevent processing huge images)
        'max_width' => env('IMAGE_MAX_WIDTH', 4096),
        'max_height' => env('IMAGE_MAX_HEIGHT', 4096),

        // Default quality for JPEG/WebP (1-100)
        'quality' => env('IMAGE_QUALITY', 85),

        // Strip EXIF data from images
        'strip_exif' => env('IMAGE_STRIP_EXIF', true),

        // Auto-orient based on EXIF
        'auto_orient' => env('IMAGE_AUTO_ORIENT', true),

        // Predefined conversions
        'conversions' => [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'fit' => 'cover',
                'quality' => 80,
            ],
            'small' => [
                'width' => 320,
                'height' => 320,
                'fit' => 'contain',
                'quality' => 85,
            ],
            'medium' => [
                'width' => 640,
                'height' => 640,
                'fit' => 'contain',
                'quality' => 85,
            ],
            'large' => [
                'width' => 1200,
                'height' => 1200,
                'fit' => 'contain',
                'quality' => 85,
            ],
            'social' => [
                'width' => 1200,
                'height' => 630,
                'fit' => 'cover',
                'quality' => 90,
            ],
        ],

        // Process images asynchronously
        'async_processing' => env('IMAGE_ASYNC_PROCESSING', true),

        // Queue name for async processing
        'queue' => env('IMAGE_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN Settings
    |--------------------------------------------------------------------------
    |
    | Configure CDN integration for serving files from edge locations.
    |
    */
    'cdn' => [
        'enabled' => env('CDN_ENABLED', false),
        'url' => env('CDN_URL'),
        'signing_key' => env('CDN_SIGNING_KEY'),
        'default_ttl' => env('CDN_DEFAULT_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Configure security features including URL signing and virus scanning.
    |
    */
    'security' => [
        // Key for signing URLs
        'signing_key' => env('FILES_SIGNING_KEY'),

        // Default TTL for signed URLs (in seconds)
        'signing_ttl' => env('FILES_SIGNING_TTL', 3600),

        // Virus scanning configuration
        'virus_scan' => [
            'enabled' => env('VIRUS_SCAN_ENABLED', false),
            'driver' => env('VIRUS_SCAN_DRIVER', 'clamav'), // 'clamav' or 'http'
            'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
            'http_endpoint' => env('VIRUS_SCAN_ENDPOINT'),
            'http_api_key' => env('VIRUS_SCAN_API_KEY'),
            'scan_on_upload' => env('VIRUS_SCAN_ON_UPLOAD', true),
            'quarantine_path' => env('VIRUS_QUARANTINE_PATH', storage_path('quarantine')),
        ],

        // Path traversal protection
        'sanitize_paths' => true,

        // Allowed characters in filenames
        'filename_pattern' => '/^[\w\-\.]+$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure upload rate limiting to prevent abuse.
    |
    */
    'rate_limit' => [
        'enabled' => env('UPLOAD_RATE_LIMIT_ENABLED', true),

        // Maximum uploads per minute per user/IP
        'max_uploads_per_minute' => env('UPLOAD_MAX_PER_MINUTE', 10),

        // Maximum bytes per hour per user/IP
        'max_bytes_per_hour' => env('UPLOAD_MAX_BYTES_PER_HOUR', 100 * 1024 * 1024),

        // Maximum concurrent uploads per user/IP
        'max_concurrent' => env('UPLOAD_MAX_CONCURRENT', 3),

        // Cache driver for rate limiting
        'cache_driver' => env('UPLOAD_RATE_LIMIT_CACHE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup of old and orphaned files.
    |
    */
    'garbage_collection' => [
        // Days before soft-deleted files are permanently removed
        'deleted_files_days' => env('GC_DELETED_FILES_DAYS', 30),

        // Hours before incomplete chunked uploads are cleaned up
        'incomplete_uploads_hours' => env('GC_INCOMPLETE_UPLOADS_HOURS', 24),

        // Days before unused conversions are removed
        'unused_conversions_days' => env('GC_UNUSED_CONVERSIONS_DAYS', 90),

        // Enable orphan file detection and cleanup
        'cleanup_orphans' => env('GC_CLEANUP_ORPHANS', true),

        // Schedule for garbage collection (cron expression)
        'schedule' => env('GC_SCHEDULE', '0 2 * * *'), // Daily at 2 AM
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tracking
    |--------------------------------------------------------------------------
    |
    | Configure database integration for tracking files and metadata.
    |
    */
    'database' => [
        'enabled' => env('FILES_DATABASE_TRACKING', true),
        'table' => 'ml_files',
        'conversions_table' => 'ml_file_conversions',

        // Track file access (updates last_accessed_at and access_count)
        'track_access' => env('FILES_TRACK_ACCESS', true),

        // Soft delete files (keep records for recovery)
        'soft_delete' => env('FILES_SOFT_DELETE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for file operations.
    |
    */
    'logging' => [
        'enabled' => env('FILES_LOGGING', true),
        'channel' => env('FILES_LOG_CHANNEL', 'default'),
        'level' => env('FILES_LOG_LEVEL', 'info'),

        // Log these events
        'events' => [
            'upload' => true,
            'download' => true,
            'delete' => true,
            'error' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Define file collections with specific settings. Collections allow you
    | to group related files with different configurations.
    |
    */
    'collections' => [
        'avatars' => [
            'disk' => 'local',
            'max_size' => 5 * 1024 * 1024, // 5MB
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'conversions' => ['thumb', 'medium'],
        ],

        'documents' => [
            'disk' => 's3',
            'max_size' => 50 * 1024 * 1024, // 50MB
            'allowed_mimes' => ['application/pdf', 'application/msword'],
            'virus_scan' => true,
        ],

        'media' => [
            'disk' => 's3',
            'max_size' => 500 * 1024 * 1024, // 500MB
            'allowed_mimes' => ['video/mp4', 'video/webm', 'audio/mpeg'],
            'chunked' => true,
        ],
    ],
];
