<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Purchasing\Domain\Models\Bill;

/**
 * Who may read, draft and post bills — the payable-side mirror of `SalesInvoicePolicy`.
 *
 * Bills are a document with a draft→post lifecycle, so they mirror `sales.invoices.*` (view/draft/post), not the
 * supplier master-data split. Company membership is checked as well as permission on every method that has a
 * bill to check against: the two are different questions and both must be true.
 *
 * `purchasing.bills.draft` is held by both the accountant and the bookkeeper — drafting a bill is ordinary
 * day-to-day work. The split that matters is *posting*, which commits the document to the ledger, so it is a
 * separate sensitive capability held by the accountant alone.
 *
 * THE STATE CHECK IN `post()` IS ADVISORY, NEVER THE ENFORCEMENT
 * -------------------------------------------------------------
 * `post()` asks the bill whether it is a draft so a client can decide whether to offer a button. That is all it
 * is for. `Gate::before` grants a tenant owner every ability outright, so a state precondition expressed only as
 * a policy would be silently skipped for the one person most able to do damage. The authoritative checks live in
 * `BillService`, backed by CHECK constraints and triggers the database enforces on everyone.
 *
 * There is no `cancel` method — cancellation is deferred this wave. There is no `BillLinePolicy` — a line is not
 * independently addressable.
 */
final class BillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.bills.view');
    }

    public function view(User $user, Bill $bill): bool
    {
        return $user->can('purchasing.bills.view')
            && $user->canAccessCompany($bill->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.bills.draft');
    }

    public function update(User $user, Bill $bill): bool
    {
        return $user->can('purchasing.bills.draft')
            && $user->canAccessCompany($bill->company_id);
    }

    /**
     * Deleting a draft.
     *
     * The same capability as changing one, because a draft that can be emptied of every line is already
     * effectively deleted. The service refuses deletion for anything that is not a draft regardless of this.
     */
    public function delete(User $user, Bill $bill): bool
    {
        return $user->can('purchasing.bills.draft')
            && $user->canAccessCompany($bill->company_id);
    }

    /**
     * Committing a draft to the ledger.
     *
     * Only a draft can be posted, and the status check is advisory — see the note above. `BillService::post()`
     * re-checks it, along with the lines, the total, every account and the fiscal period.
     */
    public function post(User $user, Bill $bill): bool
    {
        return $bill->isDraft()
            && $user->can('purchasing.bills.post')
            && $user->canAccessCompany($bill->company_id);
    }
}
