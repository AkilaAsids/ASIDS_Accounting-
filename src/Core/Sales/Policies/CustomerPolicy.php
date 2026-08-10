<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Sales\Domain\Models\Customer;

/**
 * Who may read and change customers.
 *
 * Company membership is checked as well as permission, on every method that has a customer to check it
 * against. The two are different questions and both must be true: `sales.customers.manage` does not
 * give someone business in the customer list of a company they are not a member of.
 *
 * Note what these methods do *not* decide. Whether a customer can actually be archived depends on
 * whether money is owed, and that is a business rule in `CustomerService`, not an authorisation
 * question. Phase 2 established why the split matters: `Gate::before` grants a tenant owner every
 * ability, so a state precondition expressed only as a policy would be short-circuited for owners and
 * silently skipped.
 */
final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('sales.customers.view')
            && $user->canAccessCompany($customer->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('sales.customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('sales.customers.manage')
            && $user->canAccessCompany($customer->company_id);
    }

    /**
     * Archiving and deleting share a permission but not a risk profile.
     *
     * Deletion is refused outright by the service once any invoice names the customer; archiving is the
     * ordinary path. Keeping them apart means a later decision to require more for deletion — a
     * separate capability, a step-up confirmation — has somewhere to go.
     */
    public function archive(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('sales.customers.manage')
            && $user->canAccessCompany($customer->company_id);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }
}
