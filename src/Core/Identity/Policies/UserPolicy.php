<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Policies;

use Asids\Core\Identity\Domain\Models\User;

/**
 * Authorisation for user administration.
 *
 * One rule runs through every method: **a delegated administrator may not act on someone at or
 * above their own role level.** Without it, "can manage users" would mean "can suspend the
 * owner", and the level ordering established in ADR 0003 would stop at roles.
 *
 * The owner reaches all of these through the `Gate::before` short circuit, so each method below
 * can be read as "what may a delegated administrator do".
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('identity.users.view');
    }

    public function view(User $actor, User $target): bool
    {
        // A user can always see their own record, whatever permissions they hold — otherwise a
        // bookkeeper cannot open their own profile.
        return $this->isSelf($actor, $target) || $actor->can('identity.users.view');
    }

    public function invite(User $actor): bool
    {
        return $actor->can('identity.users.invite');
    }

    public function update(User $actor, User $target): bool
    {
        if ($this->isSelf($actor, $target)) {
            // Self-edits go through the narrower profile endpoint, which excludes HR fields.
            return false;
        }

        return $actor->can('identity.users.update') && $this->outranks($actor, $target);
    }

    public function suspend(User $actor, User $target): bool
    {
        return ! $this->isSelf($actor, $target)
            && $actor->can('identity.users.suspend')
            && $this->outranks($actor, $target)
            && $target->status->canAuthenticate();
    }

    public function reinstate(User $actor, User $target): bool
    {
        return $actor->can('identity.users.suspend')
            && $this->outranks($actor, $target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return ! $this->isSelf($actor, $target)
            && $actor->can('identity.users.deactivate')
            && $this->outranks($actor, $target);
    }

    /**
     * Send another user a password reset link.
     *
     * Sensitive: it produces a credential-bearing link for someone else's account, which is why
     * the route also demands step-up authentication.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        return ! $this->isSelf($actor, $target)
            && $actor->can('identity.credentials.reset_password')
            && $this->outranks($actor, $target);
    }

    /**
     * Clear another user's second factor — the "lost my phone and my recovery codes" path.
     *
     * The most dangerous capability in this module: it removes a security control from an account
     * the actor does not own. Never permitted against a peer or a senior.
     */
    public function resetTwoFactor(User $actor, User $target): bool
    {
        return ! $this->isSelf($actor, $target)
            && $actor->can('identity.credentials.reset_two_factor')
            && $this->outranks($actor, $target);
    }

    public function viewLoginHistory(User $actor, User $target): bool
    {
        return $this->isSelf($actor, $target) || $actor->can('identity.login_history.view');
    }

    public function viewDevices(User $actor, User $target): bool
    {
        return $this->isSelf($actor, $target) || $actor->can('identity.devices.view');
    }

    public function revokeDevice(User $actor, User $target): bool
    {
        // Revoking your own device is always allowed: it is the control a user reaches for when
        // they lose a laptop, and gating it behind a permission would be actively harmful.
        return $this->isSelf($actor, $target)
            || ($actor->can('identity.devices.revoke') && $this->outranks($actor, $target));
    }

    private function isSelf(User $actor, User $target): bool
    {
        return $actor->getKey() === $target->getKey();
    }

    /**
     * Strictly greater, not greater-or-equal: two administrators at the same level must not be
     * able to suspend each other.
     */
    private function outranks(User $actor, User $target): bool
    {
        return $actor->highestRoleLevel() > $target->highestRoleLevel();
    }
}
