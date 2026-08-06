<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Policies;

use Asids\Core\Identity\Domain\Models\User;

/**
 * The permission catalogue is read-only through the API — it is synchronised from code
 * by PermissionSynchroniser — so this policy has no write methods at all. Their absence
 * is the enforcement: `Gate::denies('update', Permission::class)` for want of a method
 * is a harder guarantee than a method that returns false and could later be edited.
 */
final class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        // Anyone who can look at a role needs to see the capabilities it could hold,
        // otherwise the roles screen renders identifiers with no labels.
        return $user->can('authorization.permissions.view')
            || $user->can('authorization.roles.view');
    }
}
