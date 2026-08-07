<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Policies;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Who may read and change the chart of accounts.
 *
 * Company membership is checked as well as permission, on every method. The two are different
 * questions and both have to be true: a bookkeeper with `accounts.manage` still has no business in
 * the books of a company they are not a member of, and Phase 1's membership boundary is what says so.
 */
final class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.view')
            && $user->canAccessCompany($account->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.accounts.manage');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.manage')
            && $user->canAccessCompany($account->company_id);
    }

    /**
     * Deleting and archiving share a permission but not a risk profile, so they are separate methods.
     *
     * Deletion is refused outright by the service for any account with history; archiving is the
     * ordinary path. Keeping them apart means a future decision to require more for deletion has
     * somewhere to go.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.manage')
            && $user->canAccessCompany($account->company_id);
    }

    public function archive(User $user, Account $account): bool
    {
        return $this->update($user, $account);
    }
}
