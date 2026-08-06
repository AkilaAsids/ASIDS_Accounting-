<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Policies;

use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Authorisation for role management.
 *
 * The owner short circuit in AuthServiceProvider means these methods are only reached
 * by non-owners, so each one can be read as "what may a delegated administrator do".
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('authorization.roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        // Templates are visible so the UI can offer them as a starting point.
        return $user->can('authorization.roles.view')
            && ($role->isTemplate() || $role->tenant_id === $user->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->can('authorization.roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        if (! $user->can('authorization.roles.update') || $role->isTemplate()) {
            return false;
        }

        // A role at or above the actor's own level must not be editable: otherwise an
        // Administrator could add capabilities to a role they hold and self-escalate,
        // bypassing the assignment-level check entirely.
        return $role->level < $user->highestRoleLevel();
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('authorization.roles.delete')
            && $role->isDeletable()
            && ! $role->isTemplate()
            && $role->level < $user->highestRoleLevel();
    }

    /**
     * Assigning roles to another user.
     */
    public function assign(User $user, User $target): bool
    {
        if (! $user->can('authorization.roles.assign')) {
            return false;
        }

        // Nobody re-roles themselves. Self-assignment is the shortest path from
        // "can manage users" to "can do anything", and an administrator who genuinely
        // needs a different role should be given it by an owner.
        if ($user->getKey() === $target->getKey()) {
            return false;
        }

        // A delegated administrator may not alter someone senior to them.
        return $target->highestRoleLevel() < $user->highestRoleLevel();
    }

    /**
     * Transferring workspace ownership. Owners only, so this always returns false here
     * — the owner reaches it through the `Gate::before` short circuit.
     */
    public function transferOwnership(User $user): bool
    {
        return false;
    }
}
