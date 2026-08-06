<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;

/**
 * Authorisation for company access grants.
 *
 * The rule that matters here: nobody may grant themselves access to a company they cannot
 * already reach. Without it, "can manage company access" would silently mean "can read every
 * set of books in the workspace", and the membership boundary would be decorative.
 */
final class CompanyMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('organization.memberships.view');
    }

    public function grant(User $user, Company $company, ?User $target = null): bool
    {
        if (! $user->can('organization.memberships.grant')) {
            return false;
        }

        // The granter must already be a member of the company they are granting.
        if (! $user->canAccessCompany((string) $company->getKey())) {
            return false;
        }

        // Self-grant is refused even when the actor is already a member: there is nothing to
        // gain, and permitting it makes the "already a member" check the only barrier.
        return $target === null || $target->getKey() !== $user->getKey();
    }

    public function revoke(User $user, CompanyMembership $membership): bool
    {
        return $user->can('organization.memberships.revoke')
            && $user->canAccessCompany((string) $membership->company_id)
            // Revoking your own access would lock you out of a company you may be the only
            // member of, with no route back.
            && $membership->user_id !== $user->getKey();
    }
}
