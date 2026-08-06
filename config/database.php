<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Database configuration
|--------------------------------------------------------------------------
|
| PostgreSQL is the only supported engine. Two connections are defined against
| the same database:
|
|   pgsql        The application connection. Authenticates as a NOBYPASSRLS
|                role so PostgreSQL row level security is enforced even if an
|                Eloquent global scope is ever bypassed.
|
|   pgsql_admin  A privileged connection used exclusively by migrations that
|                must create extensions, own tables or attach RLS policies.
|                Never used to serve a request.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'asids_erp'),
            'username' => env('DB_USERNAME', 'asids_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'application_name' => env('APP_NAME', 'asids').'-web',
            // Pin the session time zone to UTC.
            //
            // Without this the connection inherits the server's zone, and every `timestamptz`
            // written through the query builder shifts. Laravel binds a Carbon as
            // 'Y-m-d H:i:s' with no offset — UTC wall time — and PostgreSQL interprets an
            // offset-less literal in the *session* zone. On a server set to Asia/Colombo that
            // stored each instant 5h30m early: audit entries, login history and
            // `last_activity_at` were all wrong, and the session idle check fired immediately
            // after sign-in because the activity timestamp appeared hours old.
            //
            // `docker/postgres/init/01-bootstrap.sh` sets `timezone = 'UTC'` on the role, which
            // is why containerised environments never showed this. Setting it on the connection
            // makes correctness independent of how the server was provisioned.
            'timezone' => 'UTC',

            // Timeouts are also set on the role itself; repeating them here
            // keeps behaviour identical when connecting from outside Docker.
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        ],

        'pgsql_admin' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'asids_erp'),
            'username' => env('DB_ADMIN_USERNAME', env('DB_USERNAME', 'asids_owner')),
            'password' => env('DB_ADMIN_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'application_name' => env('APP_NAME', 'asids').'-admin',
            'timezone' => 'UTC',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            // Every key is namespaced per deployment so a shared ElastiCache
            // cluster can host several environments without collisions.
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'asids'), '_').'_'.env('APP_ENV', 'local').'_'),
            'persistent' => (bool) env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_CACHE_DB', 1),
        ],

        // Queues must never share a database with the cache: flushing the cache
        // would otherwise destroy pending jobs.
        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_QUEUE_DB', 2),
        ],
    ],
];
