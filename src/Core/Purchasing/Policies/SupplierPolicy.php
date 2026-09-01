<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Purchasing\Domain\Models\Supplier;

/**
 * Who may read and change suppliers.
 *
 * The payable-side mirror of `CustomerPolicy`. Company membership is checked as well as permission, on
 * every method that has a supplier to check it against. The two are different questions and both must be
 * true: `purchasing.suppliers.manage` does not give someone business in the supplier list of a company
 * they are not a member of.
 *
 * Note what these methods do *not* decide. Whether a supplier can actually be archived depends on
 * whether money is owed, and that is a business rule in `SupplierService`, not an authorisation
 * question. `Gate::before` grants a tenant owner every ability, so a state precondition expressed only
 * as a policy would be short-circuited for owners and silently skipped.
 */
final class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.suppliers.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.view')
            && $user->canAccessCompany($supplier->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.manage')
            && $user->canAccessCompany($supplier->company_id);
    }

    /**
     * Archiving and deleting share a permission but not a risk profile.
     *
     * Deletion is refused outright by the service once any bill names the supplier; archiving is the
     * ordinary path. Keeping them apart means a later decision to require more for deletion — a separate
     * capability, a step-up confirmation — has somewhere to go.
     */
    public function archive(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('purchasing.suppliers.manage')
            && $user->canAccessCompany($supplier->company_id);
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }
}
