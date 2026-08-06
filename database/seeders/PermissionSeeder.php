<?php

declare(strict_types=1);

namespace Database\Seeders;

use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Seeder;

/**
 * Reconciles the `permissions` table with PermissionCatalogue.
 *
 * Required in every environment, production included: a role can hold no capability that has
 * no row, so an unseeded database yields a workspace where the owner is the only person who
 * can do anything (their authority is implicit) and every delegated role is inert.
 *
 * Idempotent — this is the same code path as `asids:sync-permissions`, which the release
 * pipeline runs after every migration.
 */
final class PermissionSeeder extends Seeder
{
    public function run(PermissionSynchroniser $synchroniser): void
    {
        // Permissions are global and are written before any tenant context exists.
        $result = RowLevelSecurity::bypass(static fn (): array => $synchroniser->sync());

        $this->command?->getOutput()->writeln(sprintf(
            '  <fg=green>Permissions synchronised:</> %d created, %d updated.',
            $result['created'],
            $result['updated'],
        ));

        if ($result['orphaned'] !== []) {
            $this->command?->getOutput()->writeln(sprintf(
                '  <fg=yellow>%d orphaned permission(s) left in place:</> %s',
                count($result['orphaned']),
                implode(', ', $result['orphaned']),
            ));
        }
    }
}
