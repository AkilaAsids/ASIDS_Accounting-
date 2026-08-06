<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Infrastructure\Bootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Binds spatie/laravel-permission's "team" to the active tenant.
 *
 * Without this the package resolves role assignments with a null team id, which means
 * a user's roles in workspace A would be evaluated against workspace B's pivot rows —
 * the exact cross-tenant authorisation failure the whole tenancy design exists to
 * prevent. It is the single most important line of wiring in the Authorization module.
 *
 * ORDERING
 * --------
 * Registered *after* CacheTagBootstrapper in `config/tenancy.php`, and that order is
 * load-bearing: the permission cache key must already be tenant-prefixed before the
 * registrar is asked for a tenant's roles, or one workspace's cached role set would
 * answer another's lookups.
 *
 * WHY THE INSTANCE IS FORGOTTEN
 * -----------------------------
 * PermissionRegistrar is a singleton that memoises the loaded permission and role
 * collections in process memory. In a queue worker that handles a job for workspace A
 * and then one for workspace B, that memory would carry A's roles into B — and because
 * the cache prefix has changed, the stale data would not even be detectable as stale.
 * Dropping the container instance forces a clean load per tenant, without deleting the
 * (correctly prefixed, still valid) cache entry, so the cost is one cache read rather
 * than a full rebuild from the database.
 */
final class PermissionTeamBootstrapper implements TenancyBootstrapper
{
    public function __construct(private readonly Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->reset();

        $this->registrar()->setPermissionsTeamId((string) $tenant->getTenantKey());
    }

    public function revert(): void
    {
        $this->reset();

        // Null, not an empty string: platform staff genuinely have no team, and the
        // package treats null as "no team constraint" while an empty string would be a
        // team whose id happens to be blank.
        $this->registrar()->setPermissionsTeamId(null);
    }

    private function reset(): void
    {
        $this->app->forgetInstance(PermissionRegistrar::class);
    }

    private function registrar(): PermissionRegistrar
    {
        return $this->app->make(PermissionRegistrar::class);
    }
}
