<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Console;

use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
use Asids\Core\Authorization\Application\Services\RoleProvisioner;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciles the permission catalogue, and optionally refreshes every workspace's system
 * roles so that capabilities added by a release reach existing customers.
 *
 * Intended to run as part of the release pipeline, immediately after `migrate`.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'asids:sync-permissions
                            {--refresh-roles : Also grant newly added capabilities to each workspace’s system roles}
                            {--force : When refreshing, overwrite system roles a customer has customised}';

    protected $description = 'Synchronise the permission catalogue from code into the database';

    public function handle(
        PermissionSynchroniser $synchroniser,
        RoleProvisioner $provisioner,
        TenantContext $tenantContext,
    ): int {
        // Permissions are global and the catalogue is written before any tenant context
        // exists, so the policies are suspended for the duration of this command only.
        $result = RowLevelSecurity::bypass(static fn (): array => $synchroniser->sync());

        $this->components->info(sprintf(
            'Permissions synchronised: %d created, %d updated.',
            $result['created'],
            $result['updated'],
        ));

        if ($result['orphaned'] !== []) {
            $this->components->warn(sprintf(
                '%d permission(s) exist in the database but not in the catalogue. They were left in place: %s',
                count($result['orphaned']),
                implode(', ', $result['orphaned']),
            ));
        }

        if (! $this->option('refresh-roles')) {
            return self::SUCCESS;
        }

        return $this->refreshRoles($provisioner, $tenantContext);
    }

    private function refreshRoles(RoleProvisioner $provisioner, TenantContext $tenantContext): int
    {
        $force = (bool) $this->option('force');
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        // One workspace's bad state must not stop the release for the rest, so failures
        // are counted and reported rather than aborting the command.
        $tenantContext->eachActiveTenant(
            callback: function (Tenant $tenant) use ($provisioner, $force, &$updated, &$skipped): void {
                $result = RowLevelSecurity::bypass(
                    static fn (): array => $provisioner->refreshSystemRolesFor($tenant, $force)
                );

                $updated += count($result['updated']);
                $skipped += count($result['skipped']);
            },
            onFailure: function (Tenant $tenant, Throwable $e) use (&$failed): void {
                $failed++;
                $this->components->error(sprintf(
                    'Workspace %s (%s): %s',
                    $tenant->slug,
                    $tenant->getKey(),
                    $e->getMessage(),
                ));
            },
        );

        $this->components->info(sprintf(
            'System roles refreshed: %d role(s) updated, %d left as customised, %d workspace(s) failed.',
            $updated,
            $skipped,
            $failed,
        ));

        // A non-zero exit is what makes the deploy pipeline notice.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
