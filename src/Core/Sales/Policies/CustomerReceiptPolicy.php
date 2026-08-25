<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;

/**
 * Who may read and record customer receipts.
 *
 * One capability this wave, `sales.receipts.manage`, because recording and allocating are a single atomic
 * action (Gate-1 #2) — there is no separate "record" and "allocate" to gate, the way `issue` and `cancel` are
 * split for invoices. The reversal sub-slice adds `sales.receipts.cancel` when it lands.
 *
 * Company membership is checked as well as permission on every method that has a receipt to check against.
 * The two are different questions and both must hold: two companies in one workspace share a `tenant_id`, so
 * row level security is satisfied by either one's rows, and only `canAccessCompany()` stops a member of one
 * company reading the other's receipts.
 *
 * THE ENFORCEMENT IS THE SERVICE, NOT THIS FILE
 * ---------------------------------------------
 * Any state check here would be advisory only, because `Gate::before` short-circuits every method for a tenant
 * owner. So `ReceiptService` is the authoritative boundary — the full-allocation invariant, the per-invoice
 * cap, the period, the posting — backed by CHECK constraints and triggers the database enforces on everyone.
 * Nothing here duplicates them.
 */
final class CustomerReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.receipts.manage');
    }

    public function view(User $user, CustomerReceipt $receipt): bool
    {
        return $user->can('sales.receipts.manage')
            && $user->canAccessCompany($receipt->company_id);
    }

    /**
     * Recording (and allocating) a receipt.
     *
     * `ReceiptService::record()` re-checks everything that matters — the amount, the customer, the bank
     * account, the allocation invariants, the fiscal period.
     */
    public function create(User $user): bool
    {
        return $user->can('sales.receipts.manage');
    }
}
