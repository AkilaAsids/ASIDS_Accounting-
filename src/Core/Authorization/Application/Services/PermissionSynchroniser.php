<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Application\Services;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles the `permissions` table with PermissionCatalogue.
 *
 * Run by the seeder and by `asids:sync-permissions` on every deploy. Idempotent, so
 * running it twice is a no-op and running it after a rollback restores the previous
 * catalogue.
 *
 * Removal is deliberately conservative. A permission that has disappeared from the
 * catalogue is *reported*, not deleted, because deleting it would cascade through
 * `role_has_permissions` and silently strip capability from live roles — and the usual
 * cause of a "missing" permission is a partially deployed release, not an intentional
 * removal. Actual removal is an explicit, reviewed migration.
 */
final readonly class PermissionSynchroniser
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @return array{created: int, updated: int, orphaned: list<string>}
     */
    public function sync(): array
    {
        $definitions = PermissionCatalogue::all();

        $result = DB::transaction(function () use ($definitions): array {
            /** @var array<string, Permission> $existing */
            $existing = Permission::query()->get()->keyBy('name')->all();

            $created = 0;
            $updated = 0;

            foreach ($definitions as $definition) {
                $row = $definition->toDatabaseRow();
                $current = $existing[$definition->name()] ?? null;

                if ($current === null) {
                    // No explicit id: `HasUuids` generates a UUID v7 on create, and `id` is
                    // deliberately not fillable — passing it would require opening mass
                    // assignment on the primary key of the capability catalogue.
                    Permission::query()->create($row);
                    $created++;

                    continue;
                }

                // Only the presentation metadata can legitimately change for an
                // existing permission; its name and parts are its identity.
                $current->fill([
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'is_sensitive' => $row['is_sensitive'],
                    'sort_order' => $row['sort_order'],
                ]);

                if ($current->isDirty()) {
                    $current->save();
                    $updated++;
                }
            }

            $catalogueNames = PermissionCatalogue::names();
            $orphaned = array_values(array_diff(array_keys($existing), $catalogueNames));

            return ['created' => $created, 'updated' => $updated, 'orphaned' => $orphaned];
        });

        if ($result['orphaned'] !== []) {
            Log::warning('Permissions exist in the database but not in the catalogue. They were left in place; remove them with an explicit migration if intended.', [
                'orphaned' => $result['orphaned'],
            ]);
        }

        // The registrar caches the whole catalogue; without this the newly created rows
        // would be invisible until the cache expired.
        $this->registrar->forgetCachedPermissions();

        return $result;
    }
}
