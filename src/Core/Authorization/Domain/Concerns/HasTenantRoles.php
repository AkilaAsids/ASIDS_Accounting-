<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Concerns;

use Asids\Core\Authorization\Domain\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Traits\HasRoles;

/**
 * Role and permission helpers for the User model, layered over
 * spatie/laravel-permission's teams mode.
 *
 * The trait exists to give the rest of the codebase a small, intention-revealing
 * vocabulary — `isTenantOwner()`, `highestRoleLevel()`, `canGrantRole()` — instead of
 * spreading `hasRole('owner')` string literals through policies and Blade. String
 * comparisons against role names are exactly what breaks when a customer renames a
 * role, and the `is_owner` column exists so that never matters.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasTenantRoles
{
    use HasRoles;

    /**
     * Memoised answers to the two questions asked on nearly every request.
     *
     * @var array<string, mixed>
     */
    protected array $authorizationState = [];

    /**
     * The permission guard. Sanctum's token abilities are a *separate*, narrower
     * check applied on top; a token cannot grant a permission the user lacks.
     */
    public function guardName(): string
    {
        return 'web';
    }

    /**
     * Whether this user holds their workspace's owner role.
     *
     * Read from the `is_owner` column rather than the role name, so a customer who
     * renames "Owner" to "Managing Director" does not lose control of their workspace.
     * Consulted by the `Gate::before` rule in AuthServiceProvider, which means it runs
     * on nearly every authorisation check — hence the memoisation.
     */
    public function isTenantOwner(): bool
    {
        return $this->rememberAuthorizationState(
            'is_owner',
            fn (): bool => $this->tenantRoles()->contains(
                static fn (Role $role): bool => $role->is_owner
            ),
        );
    }

    /**
     * Highest role level held, or zero for a user with no roles.
     *
     * Used to prevent privilege escalation: a user may only grant roles strictly below
     * their own level, so an Administrator cannot mint another Owner.
     */
    public function highestRoleLevel(): int
    {
        return $this->rememberAuthorizationState(
            'highest_level',
            fn (): int => (int) $this->tenantRoles()->max('level'),
        );
    }

    public function canGrantRole(Role $role): bool
    {
        // The owner role is transferred through an explicit, audited ownership handover,
        // never handed out as an ordinary assignment.
        if ($role->is_owner) {
            return false;
        }

        if ($role->isTemplate()) {
            return false;
        }

        return $role->level < $this->highestRoleLevel();
    }

    /**
     * Roles held in the current workspace.
     *
     * @return Collection<int, Role>
     */
    public function tenantRoles(): Collection
    {
        /** @var Collection<int, Role> $roles */
        $roles = $this->relationLoaded('roles')
            ? $this->getRelation('roles')
            : $this->roles()->get();

        return $roles;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        /** @var list<string> $names */
        $names = $this->getAllPermissions()->pluck('name')->all();

        return $names;
    }

    /**
     * Clears the memoised role state. Called by RoleService after any assignment
     * change, because a stale `isTenantOwner()` inside one request would otherwise
     * out-live the revocation that was supposed to take effect.
     */
    public function forgetAuthorizationState(): void
    {
        $this->authorizationState = [];

        // spatie already flushes its own permission cache when roles are attached or
        // synced; what it cannot know about is this model instance's loaded relations
        // and the memoised answers above.
        $this->unsetRelation('roles');
        $this->unsetRelation('permissions');
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $resolver
     * @return TValue
     */
    private function rememberAuthorizationState(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->authorizationState)) {
            $this->authorizationState[$key] = $resolver();
        }

        /** @var TValue $value */
        $value = $this->authorizationState[$key];

        return $value;
    }
}
