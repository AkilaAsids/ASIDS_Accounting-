<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Filesystems
|--------------------------------------------------------------------------
|
| No disk is publicly readable. Every tenant document — an invoice PDF, a bank
| statement, a payslip — is served through a short-lived signed URL issued by
| the application after a policy check, never by a public bucket path.
|
| Tenant scoping of the disk roots is applied at runtime by the tenancy
| FilesystemBootstrapper, so application code simply writes to `documents`.
|
*/

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
        ],

        // Build artefacts and other genuinely public assets only.
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => true,
        ],

        // Tenant business documents.
        'documents' => [
            'driver' => env('DOCUMENTS_DISK_DRIVER', env('FILESYSTEM_DISK', 'local') === 's3' ? 's3' : 'local'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => storage_path('app/private/documents'),
            'visibility' => 'private',
            'throw' => true,
            'options' => [
                // Server-side encryption is mandatory for anything containing
                // customer financial data.
                'ServerSideEncryption' => 'aws:kms',
                'SSEKMSKeyId' => env('AWS_KMS_KEY_ID'),
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
        ],

        // Database and configuration backups. Written by scheduled jobs only.
        'backups' => [
            'driver' => env('BACKUP_DISK_DRIVER', 'local'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
            'bucket' => env('AWS_BACKUP_BUCKET', env('AWS_BUCKET')),
            'root' => storage_path('app/private/backups'),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],

    // `storage:link` intentionally exposes only the public disk.
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
