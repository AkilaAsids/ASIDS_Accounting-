<?php

declare(strict_types=1);

use Asids\Core\Platform\Support\Logging\AddTenantContext;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Logging
|--------------------------------------------------------------------------
|
| Three concerns are kept on separate channels because they have different
| audiences and different retention rules:
|
|   application  Ordinary diagnostics for engineers.
|   security     Authentication and authorisation events for the security team.
|   audit        Fallback for audit writes that could not reach the database.
|
| Every channel is enriched with the tenant, user and request identifiers so a
| support ticket can be traced end to end without guessing.
|
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => (bool) env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [AddTenantContext::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [AddTenantContext::class],
        ],

        // Structured JSON on stdout — the only sane choice inside a container,
        // where the platform's log shipper owns rotation and retention.
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'formatter_with' => [
                'appendNewline' => true,
            ],
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [AddTenantContext::class],
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'info',
            'days' => (int) env('LOG_SECURITY_DAYS', 90),
            'replace_placeholders' => true,
            'tap' => [AddTenantContext::class],
        ],

        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit-fallback.log'),
            'level' => 'info',
            'days' => (int) env('LOG_AUDIT_FALLBACK_DAYS', 365),
            'replace_placeholders' => true,
            'tap' => [AddTenantContext::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];
