<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Policies;

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Authorisation for personal access tokens.
 *
 * A token is a credential belonging to one user, so ownership — not seniority — is the deciding
 * factor. Nobody, including an owner, may list or use another user's tokens: reading a token row
 * reveals its abilities and last use, and revoking someone else's integration without their
 * knowledge is an outage nobody can explain. An administrator who needs to cut off a departing
 * employee's integrations deactivates the account, which revokes every token with a recorded
 * reason.
 */
final class AccessTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('identity.tokens.view');
    }

    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->owns($user, $token) && $user->can('identity.tokens.view');
    }

    public function create(User $user): bool
    {
        return $user->can('identity.tokens.create');
    }

    public function delete(User $user, PersonalAccessToken $token): bool
    {
        // Revoking your own token is always permitted, even without the permission: a user who
        // suspects a token has leaked must be able to kill it without waiting for an administrator.
        return $this->owns($user, $token);
    }

    private function owns(User $user, PersonalAccessToken $token): bool
    {
        return (string) $token->tokenable_id === (string) $user->getKey();
    }
}
