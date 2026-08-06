<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;

/**
 * Authorisation for branches.
 *
 * Branch access derives from company access: a branch is a dimension inside a company, so
 * there is no coherent state in which someone may manage a branch of books they cannot see.
 */
final class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('organization.branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('organization.branches.view')
            && $this->hasCompanyAccess($user, $branch);
    }

    public function create(User $user, ?Company $company = null): bool
    {
        if (! $user->can('organization.branches.create')) {
            return false;
        }

        return $company === null
            || $user->canAccessCompany((string) $company->getKey());
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('organization.branches.update')
            && $this->hasCompanyAccess($user, $branch)
            && $branch->isActive();
    }

    public function archive(User $user, Branch $branch): bool
    {
        return $user->can('organization.branches.archive')
            && $this->hasCompanyAccess($user, $branch)
            && $branch->isActive()
            // Reported as "not permitted" rather than reaching the service's clearer
            // message; the service still guards it, so the better message wins whenever the
            // caller holds the permission.
            && ! $branch->is_primary;
    }

    public function restore(User $user, Branch $branch): bool
    {
        return $user->can('organization.branches.archive')
            && $this->hasCompanyAccess($user, $branch)
            && ! $branch->isActive();
    }

    public function makePrimary(User $user, Branch $branch): bool
    {
        return $user->can('organization.branches.update')
            && $this->hasCompanyAccess($user, $branch)
            && $branch->isActive();
    }

    private function hasCompanyAccess(User $user, Branch $branch): bool
    {
        return $user->canAccessCompany((string) $branch->company_id);
    }
}
