<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;

/**
 * Authorisation for companies.
 *
 * Every method combines a permission with a membership check, and both must pass. The
 * permission answers "may this person manage companies at all"; the membership answers "may
 * they manage *this* one". A workspace administrator who is not a member of a group company
 * can see it in the list but cannot open its books — which is the whole point of separating
 * the two.
 */
final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('organization.companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('organization.companies.view')
            && $user->canAccessCompany((string) $company->getKey());
    }

    public function create(User $user): bool
    {
        return $user->can('organization.companies.create');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('organization.companies.update')
            && $user->canAccessCompany((string) $company->getKey())
            && $company->isActive();
    }

    public function archive(User $user, Company $company): bool
    {
        return $user->can('organization.companies.archive')
            && $user->canAccessCompany((string) $company->getKey())
            && $company->isActive();
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->can('organization.companies.archive') && ! $company->isActive();
    }

    /**
     * Changing which company the workspace defaults to affects every user, so it is treated
     * as a workspace-level change rather than a company edit.
     */
    public function makeDefault(User $user, Company $company): bool
    {
        return $user->can('settings.workspace.update')
            && $user->canAccessCompany((string) $company->getKey())
            && $company->isActive();
    }
}
