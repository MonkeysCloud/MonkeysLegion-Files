<?php
// config/files.php

return [
    // Which disk to use by default (can be overridden via FILES_DISK env var)
    'default_disk' => (string) (getenv('FILES_DISK') ?: 'local'),

    // Maximum upload size in bytes (fallback: 20 MiB)
    'max_bytes'    => (int) (getenv('UPLOAD_MAX_BYTES') ?: 20 * 1024 * 1024),

    // Allowed MIME types (comma-separated env var)
    'mime_allow'   => explode(
        ',',
        getenv('UPLOAD_MIME_ALLOW')
            ?: 'image/jpeg,image/png,image/webp,application/pdf'
    ),

    // Signing key for ml_files_sign_url(); must be set in env for signed URLs to work
    'signing_key'  => (string) (getenv('FILES_SIGNING_KEY') ?: ''),

    'disks' => [
        'local' => [
            // storage root: two levels up from config/, then storage/app
            'root'            => dirname(__DIR__, 2) . '/storage/app',
            // public base URL for direct access (e.g. a CDN); empty = private
            'public_base_url' => (string) (getenv('FILES_PUBLIC_URL') ?: ''),
        ],

        // Example S3 stub:
        // 's3' => [
        //     'bucket'            => getenv('AWS_BUCKET') ?: '',
        //     'region'            => getenv('AWS_REGION') ?: 'us-east-1',
        //     'prefix'            => getenv('AWS_PREFIX') ?: 'uploads/',
        //     'public_base_url'   => getenv('FILES_CDN_URL') ?: '',
        // ],

        // Example GCS stub:
        // 'gcs' => [
        //     'project_id'        => getenv('GCP_PROJECT_ID') ?: '',
        //     'key_file_path'     => getenv('GCP_KEY_FILE') ?: '',   // path to JSON
        //     'bucket'            => getenv('GCS_BUCKET') ?: '',
        //     'prefix'            => getenv('GCS_PREFIX') ?: 'uploads/',
        //     'public_base_url'   => getenv('FILES_CDN_URL') ?: '',
        // ],
    ],
];