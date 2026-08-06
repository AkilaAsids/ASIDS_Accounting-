<?php

declare(strict_types=1);

use Asids\Core\Authorization\Infrastructure\Bootstrappers\PermissionTeamBootstrapper;
use Asids\Core\Tenancy\Domain\Models\Domain;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\Bootstrappers\CacheTagBootstrapper;
use Asids\Core\Tenancy\Infrastructure\Bootstrappers\FilesystemBootstrapper;
use Asids\Core\Tenancy\Infrastructure\Bootstrappers\QueueBootstrapper;
use Asids\Core\Tenancy\Infrastructure\Bootstrappers\RowLevelSecurityBootstrapper;

/*
|--------------------------------------------------------------------------
| Tenancy (stancl/tenancy — single database mode)
|--------------------------------------------------------------------------
|
| ASIDS runs *single database* tenancy: every tenant-owned table carries a
| tenant_id and is filtered by an Eloquent global scope backed by PostgreSQL row
| level security. The database-per-tenant bootstrappers shipped with the package
| are therefore deliberately absent from the bootstrappers list.
|
| See docs/adr/0001-tenancy-strategy.md.
|
*/

return [

    'tenant_model' => Tenant::class,
    'id_generator' => null, // Tenant primary keys are UUID v7 from HasUuids.

    'domain_model' => Domain::class,

    /*
    |--------------------------------------------------------------------------
    | Central domains
    |--------------------------------------------------------------------------
    | Requests arriving on these hosts are never associated with a tenant; they
    | serve the marketing site, sign-up and the platform back office.
    */
    'central_domains' => array_values(array_unique(array_filter([
        env('TENANCY_CENTRAL_DOMAIN', 'localhost'),
        'localhost',
        '127.0.0.1',
    ]))),

    /*
    |--------------------------------------------------------------------------
    | Bootstrappers
    |--------------------------------------------------------------------------
    | Executed, in order, whenever tenancy is initialised. Each one scopes a
    | shared piece of infrastructure to the current tenant so that a cache key,
    | a queued job or an uploaded file can never bleed across tenants.
    */
    'bootstrappers' => [
        RowLevelSecurityBootstrapper::class,
        // Must precede PermissionTeamBootstrapper: the permission cache key has to be
        // tenant-prefixed before the registrar is asked for this tenant's roles.
        CacheTagBootstrapper::class,
        PermissionTeamBootstrapper::class,
        FilesystemBootstrapper::class,
        QueueBootstrapper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'tag_base' => 'tenant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem
    |--------------------------------------------------------------------------
    | Tenant uploads are prefixed rather than placed on separate disks, so a
    | single S3 bucket with a lifecycle policy covers the whole platform while
    | signed URLs remain per-tenant.
    */
    'filesystem' => [
        'suffix_base' => 'tenants/',
        'disks' => ['local', 'public', 's3'],
        'root_override' => [
            'local' => '%storage_path%/app/tenants/%tenant_id%/',
            'public' => '%storage_path%/app/public/tenants/%tenant_id%/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    | Not used: prefixing is handled by CacheTagBootstrapper so that a tenant's
    | keys can be invalidated as a set.
    */
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    | Cross-domain impersonation and the universal route feature are disabled:
    | ASIDS exposes an explicit, audited impersonation flow instead.
    */
    'features' => [],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    | There are no per-tenant migrations in single database mode; every module
    | registers its migrations with the central connection.
    */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [],
        '--realpath' => true,
    ],

    'seeder_parameters' => [],
];
