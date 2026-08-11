<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Sales\Domain\Models\SalesInvoice;

/**
 * Who may read and change sales invoices.
 *
 * Company membership is checked as well as permission on every method that has an invoice to check against. The
 * two are different questions and both must be true: `sales.invoices.draft` gives nobody business in the sales
 * ledger of a company they are not a member of.
 *
 * WHAT IS DELIBERATELY ABSENT
 * ---------------------------
 * There is no `issue` and no `cancel`. Both are Milestone 5, and declaring them here first would mean writing
 * authorisation for operations that do not exist — untestable, and the sort of speculative surface that later
 * turns out to have guarded the wrong thing.
 *
 * `sales.invoices.draft` is therefore held by both the accountant and the bookkeeper, and that is not an
 * oversight: drafting an invoice is ordinary day-to-day work. The split that matters in this domain is
 * *issuing*, which commits the document to the ledger and to the customer, and that capability arrives with
 * the transition it guards.
 *
 * There is no `SalesInvoiceLinePolicy` either. A line is not independently addressable — it is created,
 * replaced or destroyed as part of its invoice — so authorising one separately would invite a caller to reach
 * for a line without its document.
 *
 * Note what these methods do *not* decide. Whether an invoice may actually be changed depends on whether it is
 * still a draft, and that is a business rule in `SalesInvoiceService`, not an authorisation question. The split
 * matters because `Gate::before` grants a tenant owner every ability: a state precondition expressed only as a
 * policy would be short-circuited for owners and silently skipped, which is the trap Phase 2 documented.
 */
final class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.invoices.view');
    }

    public function view(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales.invoices.view')
            && $user->canAccessCompany($invoice->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('sales.invoices.draft');
    }

    public function update(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales.invoices.draft')
            && $user->canAccessCompany($invoice->company_id);
    }

    /**
     * Deleting a draft.
     *
     * The same capability as changing one, because a draft that can be emptied of every line and left at zero is
     * already effectively deleted — withholding the delete while allowing the edit would be a distinction
     * without a difference.
     *
     * Kept as its own method rather than aliased, so that a later decision to require more for deletion has
     * somewhere to go. The service refuses deletion for anything that is not a draft regardless of this.
     */
    public function delete(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales.invoices.draft')
            && $user->canAccessCompany($invoice->company_id);
    }
}
