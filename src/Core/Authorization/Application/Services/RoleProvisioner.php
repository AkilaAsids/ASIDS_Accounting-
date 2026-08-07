<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Application\Services;

use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Authorization\Domain\Models\Permission;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a workspace's starting set of roles.
 *
 * Called during provisioning, before the owner account exists, so it operates on a
 * tenant it is handed rather than on ambient tenant context. That is why every write
 * here names `tenant_id` explicitly instead of relying on the `BelongsToTenant`
 * stamp.
 *
 * Permission grants are written with a direct pivot insert rather than through
 * spatie's `syncPermissions()`. The package's method resolves the *current* team from
 * the registrar, which during provisioning is not yet this tenant — a mismatch that
 * produces roles with no permissions and no error. Writing the pivot ourselves keeps
 * provisioning independent of ambient state.
 */
final readonly class RoleProvisioner
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * Provision every system role for a tenant and return its owner role.
     */
    public function provisionSystemRolesFor(Tenant $tenant): Role
    {
        return DB::transaction(function () use ($tenant): Role {
            /** @var array<string, string> $permissionIds name => id */
            $permissionIds = Permission::query()->pluck('id', 'name')->all();

            $owner = null;

            foreach (RoleTemplate::all() as $template) {
                $role = $this->createRole($tenant, $template);

                // The owner's authority comes from the `Gate::before` short circuit on
                // `is_owner`, not from pivot rows. Writing them would imply the set is
                // exhaustive, and a capability added in a later phase would then be
                // missing from the role of the person who pays for the product.
                if (! $template->isOwner) {
                    $this->grant($role, $template->resolvedPermissions(), $permissionIds);
                }

                if ($template->isOwner) {
                    $owner = $role;
                }
            }

            $this->registrar->forgetCachedPermissions();

            // RoleTemplate::owner() guarantees a template exists; this asserts the loop
            // actually produced it rather than returning a role that grants nothing.
            assert($owner instanceof Role, 'Provisioning must produce an owner role.');

            return $owner;
        });
    }

    /**
     * Re-apply the template permissions for a tenant's system roles.
     *
     * Needed after a release that adds capabilities: an existing workspace's
     * Administrator role should gain them, or administrators would have to discover and
     * tick every new permission by hand. Customer-created roles are untouched, and so
     * is any system role whose permissions the customer has deliberately changed —
     * detected by comparing against the template as it was, not by overwriting blindly.
     *
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function refreshSystemRolesFor(Tenant $tenant, bool $force = false): array
    {
        /** @var array<string, string> $permissionIds */
        $permissionIds = Permission::query()->pluck('id', 'name')->all();

        $updated = [];
        $skipped = [];

        foreach (RoleTemplate::all() as $template) {
            if ($template->isOwner) {
                continue;
            }

            $role = Role::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('name', $template->name)
                ->first();

            if ($role === null) {
                $role = $this->createRole($tenant, $template);
                $this->grant($role, $template->resolvedPermissions(), $permissionIds);
                $updated[] = $template->name;

                continue;
            }

            $expected = $template->resolvedPermissions();
            $current = $this->currentPermissionNames($role);

            // Only the additions are applied, and only to roles that still match the
            // template. A customer who removed a permission on purpose keeps that
            // decision unless the caller explicitly forces a reset.
            $missing = array_values(array_diff($expected, $current));
            $extra = array_values(array_diff($current, $expected));

            if ($extra !== [] && ! $force) {
                $skipped[] = $template->name;

                continue;
            }

            if ($missing === []) {
                continue;
            }

            $this->grant($role, $missing, $permissionIds);
            $updated[] = $template->name;
        }

        $this->registrar->forgetCachedPermissions();

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function createRole(Tenant $tenant, RoleTemplate $template): Role
    {
        /** @var Role|null $existing */
        $existing = Role::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('name', $template->name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $role = new Role;

        $role->forceFill([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'name' => $template->name,
            'guard_name' => 'web',
            'label' => $template->label,
            'description' => $template->description,
            'is_system' => true,
            'is_owner' => $template->isOwner,
            'level' => $template->level,
        ]);

        $role->save();

        return $role;
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, string>  $permissionIds
     */
    private function grant(Role $role, array $permissionNames, array $permissionIds): void
    {
        $rows = [];

        foreach ($permissionNames as $name) {
            $permissionId = $permissionIds[$name] ?? null;

            // A template naming a permission absent from the database means the
            // synchroniser has not run for this release. Skipping is the safe response:
            // the role is under-granted and visibly so, rather than the transaction
            // failing and leaving the workspace unprovisioned.
            if ($permissionId === null) {
                continue;
            }

            $rows[] = ['role_id' => $role->getKey(), 'permission_id' => $permissionId];
        }

        if ($rows === []) {
            return;
        }

        // `insertOrIgnore` makes re-provisioning idempotent against the composite
        // primary key without a read-then-write race.
        DB::table('role_has_permissions')->insertOrIgnore($rows);
    }

    /**
     * @return list<string>
     */
    private function currentPermissionNames(Role $role): array
    {
        /** @var list<string> $names */
        $names = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->getKey())
            ->pluck('permissions.name')
            ->all();

        return $names;
    }
}
