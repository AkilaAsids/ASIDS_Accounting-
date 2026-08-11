<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Sales\Domain\Models\TaxCode;

/**
 * Who may read and change tax codes.
 *
 * Company membership is checked as well as permission on every method that has a tax code to check it
 * against. The two are different questions and both must be true: `sales.tax-codes.manage` does not give
 * someone business in the tax configuration of a company they are not a member of.
 *
 * WHY MANAGEMENT IS SPLIT FROM VIEWING
 * ------------------------------------
 * Reading a rate is what anyone raising an invoice needs. Changing one alters what every invoice under
 * that code charges, what the ledger posts to the tax liability, and what the return reports — so the two
 * are separate capabilities and only the accountant template holds the second. A bookkeeper entering
 * day-to-day sales needs to see which codes exist; deciding what they charge is a professional judgement.
 *
 * Note what these methods do *not* decide. Whether a rate can actually be changed depends on whether an
 * invoice has already used it, and that is a business rule in `TaxCodeService` rather than an
 * authorisation question. The split matters because `Gate::before` grants a tenant owner every ability:
 * a state precondition expressed only as a policy would be short-circuited for owners and silently
 * skipped, which is the trap Phase 2 documented.
 */
final class TaxCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.tax-codes.view');
    }

    public function view(User $user, TaxCode $taxCode): bool
    {
        return $user->can('sales.tax-codes.view')
            && $user->canAccessCompany($taxCode->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('sales.tax-codes.manage');
    }

    public function update(User $user, TaxCode $taxCode): bool
    {
        return $user->can('sales.tax-codes.manage')
            && $user->canAccessCompany($taxCode->company_id);
    }

    /**
     * Ending a rate's effective range.
     *
     * Its own method rather than folded into `update`, because it is the sanctioned way to change what a
     * code charges and a business may later want it held by someone `update` is withheld from. Same
     * capability today; somewhere to diverge tomorrow.
     */
    public function endRange(User $user, TaxCode $taxCode): bool
    {
        return $this->update($user, $taxCode);
    }

    public function deactivate(User $user, TaxCode $taxCode): bool
    {
        return $this->update($user, $taxCode);
    }

    public function reactivate(User $user, TaxCode $taxCode): bool
    {
        return $this->update($user, $taxCode);
    }

    /**
     * Deleting and deactivating share a permission but not a risk profile.
     *
     * Deletion is refused outright by the service once any document has used the code; deactivating is the
     * ordinary path. Keeping them apart means a later decision to require more for deletion — a separate
     * capability, a step-up confirmation — has somewhere to go.
     */
    public function delete(User $user, TaxCode $taxCode): bool
    {
        return $user->can('sales.tax-codes.manage')
            && $user->canAccessCompany($taxCode->company_id);
    }

    public function restore(User $user, TaxCode $taxCode): bool
    {
        return $this->update($user, $taxCode);
    }
}
