<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Application\Services;

use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Events\RoleAssignmentChanged;
use Asids\Core\Authorization\Domain\Events\RolePermissionsChanged;
use Asids\Core\Authorization\Domain\Exceptions\LastOwnerCannotBeRemoved;
use Asids\Core\Authorization\Domain\Exceptions\PermissionNotGrantable;
use Asids\Core\Authorization\Domain\Exceptions\RoleInUse;
use Asids\Core\Authorization\Domain\Exceptions\RoleNotGrantable;
use Asids\Core\Authorization\Domain\Exceptions\SystemRoleIsProtected;
use Asids\Core\Authorization\Domain\Models\Permission;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * All mutation of roles and role assignments.
 *
 * Every method here enforces at least one invariant that cannot be expressed as a
 * database constraint, which is the reason the service exists rather than controllers
 * calling spatie directly:
 *
 *   * A role may never grant a platform-staff capability.
 *   * A user may never assign a role at or above their own level (no self-promotion,
 *     no minting a peer).
 *   * The owner role is transferred, never assigned.
 *   * A workspace always retains at least one owner.
 *   * A system role's identity is fixed; its permissions are not.
 */
final readonly class RoleService
{
    public function __construct(
        private TenantContext $tenantContext,
        private PermissionRegistrar $registrar,
    ) {}

    public function create(RoleData $data, User $actor): Role
    {
        $tenant = $this->tenantContext->require();
        $permissions = $this->resolveGrantablePermissions($data->permissionNames);

        $name = $data->name ?? Str::slug($data->label, '_');

        if ($this->nameExists($name)) {
            throw ResourceConflict::duplicate('role', 'name', $name);
        }

        return DB::transaction(function () use ($tenant, $data, $name, $permissions, $actor): Role {
            $role = new Role();

            $role->forceFill([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->getKey(),
                'name' => $name,
                'guard_name' => 'web',
                'label' => $data->label,
                'description' => $data->description,
                'is_system' => false,
                'is_owner' => false,
                // A new role sits strictly below its creator, so creating a role can
                // never be a route to escalation.
                'level' => min(
                    $data->level ?? max($actor->highestRoleLevel() - 10, 1),
                    max($actor->highestRoleLevel() - 1, 1),
                ),
            ]);

            $role->save();

            $this->syncPermissionPivot($role, $permissions);
            $this->registrar->forgetCachedPermissions();

            RolePermissionsChanged::dispatch($role, [], array_keys($permissions), $actor);

            return $role;
        });
    }

    public function update(Role $role, RoleData $data, User $actor): Role
    {
        if ($role->isTemplate()) {
            throw RoleNotGrantable::platformTemplate();
        }

        // A system role keeps its label and name; only its permissions may move.
        if ($role->is_system && $data->label !== $role->label) {
            throw SystemRoleIsProtected::cannotRename($role->label);
        }

        $permissions = $role->hasEditablePermissions()
            ? $this->resolveGrantablePermissions($data->permissionNames)
            : [];

        return DB::transaction(function () use ($role, $data, $permissions, $actor): Role {
            if (! $role->is_system) {
                $role->label = $data->label;
            }

            $role->description = $data->description;
            $role->save();

            if ($role->hasEditablePermissions()) {
                $before = $this->currentPermissionNames($role);

                $this->syncPermissionPivot($role, $permissions);
                $this->registrar->forgetCachedPermissions();

                RolePermissionsChanged::dispatch($role, $before, array_keys($permissions), $actor);
            }

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->isTemplate()) {
            throw RoleNotGrantable::platformTemplate();
        }

        if (! $role->isDeletable()) {
            throw SystemRoleIsProtected::cannotDelete($role->label);
        }

        $assigned = $this->assignedUserCount($role);

        if ($assigned > 0) {
            throw RoleInUse::by($role->label, $assigned);
        }

        DB::transaction(function () use ($role): void {
            DB::table('role_has_permissions')->where('role_id', $role->getKey())->delete();
            $role->delete();
            $this->registrar->forgetCachedPermissions();
        });
    }

    /**
     * Replace a user's roles wholesale.
     *
     * @param  list<string>  $roleIds
     */
    public function assign(User $user, array $roleIds, User $actor): User
    {
        $tenant = $this->tenantContext->require();

        /** @var array<int, Role> $roles */
        $roles = Role::query()
            ->assignable()
            ->whereIn('id', $roleIds)
            ->get()
            ->all();

        // An id that resolved to nothing is refused rather than quietly dropped: the
        // caller believed they were granting something, and silently granting less is
        // the kind of divergence nobody notices until an audit.
        if (count($roles) !== count(array_unique($roleIds))) {
            throw RoleNotGrantable::platformTemplate();
        }

        foreach ($roles as $role) {
            if ($role->is_owner) {
                throw RoleNotGrantable::ownerRole();
            }

            if (! $actor->canGrantRole($role)) {
                throw RoleNotGrantable::aboveOwnLevel($role->label);
            }
        }

        $previous = $this->assignedRoleNames($user);

        // Losing the last owner is checked against the resulting state, not the
        // requested one, because the request is a full replacement: if the target
        // currently holds owner and the new set omits it, that is a removal.
        if ($this->wouldRemoveOwner($user, $roles)) {
            $this->assertAnotherOwnerExists($user);
        }

        return DB::transaction(function () use ($user, $roles, $tenant, $previous, $actor): User {
            // The team id is set explicitly rather than relying on the bootstrapper,
            // because this also runs from console commands with no HTTP request.
            $this->registrar->setPermissionsTeamId((string) $tenant->getKey());

            $user->syncRoles($roles);
            $user->forgetAuthorizationState();

            RoleAssignmentChanged::dispatch(
                $user,
                $previous,
                array_map(static fn (Role $role): string => $role->name, $roles),
                $actor,
            );

            return $user;
        });
    }

    /**
     * Hand workspace ownership to another user.
     *
     * Modelled as its own operation rather than as an ordinary assignment: it is the
     * one privilege change that increases someone's authority above the actor's, so it
     * needs its own permission, its own audit event and — enforced at the controller —
     * step-up authentication.
     */
    public function transferOwnership(User $from, User $to, User $actor): void
    {
        $tenant = $this->tenantContext->require();

        $ownerRole = Role::query()
            ->assignable()
            ->where('is_owner', true)
            ->firstOrFail();

        DB::transaction(function () use ($from, $to, $ownerRole, $tenant, $actor): void {
            $this->registrar->setPermissionsTeamId((string) $tenant->getKey());

            $previousFrom = $this->assignedRoleNames($from);
            $previousTo = $this->assignedRoleNames($to);

            // Granted before it is removed, so there is no instant in the transaction
            // where the workspace has no owner.
            $to->assignRole($ownerRole);
            $from->removeRole($ownerRole);

            $from->forgetAuthorizationState();
            $to->forgetAuthorizationState();

            RoleAssignmentChanged::dispatch($to, $previousTo, $this->assignedRoleNames($to), $actor);
            RoleAssignmentChanged::dispatch($from, $previousFrom, $this->assignedRoleNames($from), $actor);
        });
    }

    /**
     * Resolve permission names to models, refusing anything a workspace role may not
     * hold.
     *
     * @param  list<string>  $names
     * @return array<string, Permission> name => model
     */
    private function resolveGrantablePermissions(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $grantable = PermissionCatalogue::tenantGrantableNames();
        $rejected = array_values(array_diff($names, $grantable));

        if ($rejected !== []) {
            throw PermissionNotGrantable::these($rejected);
        }

        /** @var array<string, Permission> $found */
        $found = Permission::query()->whereIn('name', $names)->get()->keyBy('name')->all();

        $missing = array_values(array_diff($names, array_keys($found)));

        if ($missing !== []) {
            throw PermissionNotGrantable::these($missing);
        }

        return $found;
    }

    /**
     * @param  array<string, Permission>  $permissions
     */
    private function syncPermissionPivot(Role $role, array $permissions): void
    {
        // Written directly rather than via `syncPermissions()` so the operation does not
        // depend on the registrar's ambient team, which differs between an HTTP request
        // and a console command.
        DB::table('role_has_permissions')->where('role_id', $role->getKey())->delete();

        if ($permissions === []) {
            return;
        }

        DB::table('role_has_permissions')->insert(array_map(
            static fn (Permission $permission): array => [
                'role_id' => $role->getKey(),
                'permission_id' => $permission->getKey(),
            ],
            array_values($permissions),
        ));
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

    /**
     * @return list<string>
     */
    private function assignedRoleNames(User $user): array
    {
        return array_values($user->tenantRoles()
            ->map(static fn (Role $role): string => $role->name)
            ->all());
    }

    private function assignedUserCount(Role $role): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->count();
    }

    private function nameExists(string $name): bool
    {
        return Role::query()->assignable()->where('name', $name)->exists();
    }

    /**
     * @param  array<int, Role>  $newRoles
     */
    private function wouldRemoveOwner(User $user, array $newRoles): bool
    {
        if (! $user->isTenantOwner()) {
            return false;
        }

        foreach ($newRoles as $role) {
            if ($role->is_owner) {
                return false;
            }
        }

        return true;
    }

    private function assertAnotherOwnerExists(User $excluding): void
    {
        $ownerRoleId = Role::query()->assignable()->where('is_owner', true)->value('id');

        if ($ownerRoleId === null) {
            throw LastOwnerCannotBeRemoved::forWorkspace();
        }

        $others = DB::table('model_has_roles')
            ->where('role_id', $ownerRoleId)
            ->where('model_uuid', '!=', $excluding->getKey())
            ->count();

        if ($others === 0) {
            throw LastOwnerCannotBeRemoved::forWorkspace();
        }
    }
}
