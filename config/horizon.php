<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Laravel Horizon
|--------------------------------------------------------------------------
|
| Queues are separated by service-level objective rather than by module:
|
|   critical   Anything a user is waiting on (2FA mail, password reset).
|   default    Ordinary domain work (document posting, notifications).
|   audit      Append-only audit writes. Isolated so a flood of business jobs
|              can never delay the compliance trail.
|   reports    Long-running exports and financial statements.
|   search     Meilisearch index synchronisation.
|
*/

return [

    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'ops/horizon'),

    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'asids'), '_').'_horizon:'),

    'middleware' => ['web', 'auth:sanctum'],

    'waits' => [
        'redis:critical' => 15,
        'redis:default' => 60,
        'redis:audit' => 30,
        'redis:reports' => 300,
        'redis:search' => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    'defaults' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue' => ['critical'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 5,
            'timeout' => 30,
            'nice' => 0,
        ],

        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'search'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-audit' => [
            'connection' => 'redis',
            'queue' => ['audit'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // The audit trail must not silently drop an entry: retry hard, then
            // land in failed_jobs where an operator alert picks it up.
            'tries' => 10,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-reports' => [
            'connection' => 'redis',
            'queue' => ['reports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 2,
            'timeout' => 900,
            'nice' => 10,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-critical' => ['minProcesses' => 2, 'maxProcesses' => 10],
            'supervisor-default' => ['minProcesses' => 4, 'maxProcesses' => 40],
            'supervisor-audit' => ['minProcesses' => 2, 'maxProcesses' => 8],
            'supervisor-reports' => ['minProcesses' => 1, 'maxProcesses' => 8],
        ],

        'staging' => [
            'supervisor-critical' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 6],
            'supervisor-audit' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-reports' => ['minProcesses' => 1, 'maxProcesses' => 2],
        ],

        'local' => [
            'supervisor-critical' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-audit' => ['minProcesses' => 1, 'maxProcesses' => 1],
            'supervisor-reports' => ['minProcesses' => 1, 'maxProcesses' => 1],
        ],
    ],
];
