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
 * `sales.invoices.draft` is held by both the accountant and the bookkeeper, and that is not an oversight:
 * drafting an invoice is ordinary day-to-day work. The split that matters in this domain is *issuing*, which
 * commits the document to the ledger and to the customer — so `issue` and `cancel` are separate sensitive
 * capabilities held by the accountant alone, following `accounting.journals.post` and `.reverse`.
 *
 * THE STATE CHECKS HERE ARE ADVISORY, NEVER THE ENFORCEMENT
 * --------------------------------------------------------
 * `issue()` and `cancel()` ask the invoice about its status, matching `JournalEntryPolicy::post()` and
 * `reverse()`. That is so a client can decide whether to offer a button without attempting the operation to
 * find out — and it is *all* it is for.
 *
 * The reason it cannot be more is `Gate::before`: a tenant owner is granted every ability outright, so every
 * method here is short-circuited for them and the status check below never runs. A state precondition
 * expressed only as a policy would therefore be silently skipped for the one person most able to do damage.
 *
 * So the authoritative checks live in `SalesInvoiceService` — draft-ness, the fiscal period, the posting, the
 * payment figures, every transition rule — backed by CHECK constraints and triggers the database enforces on
 * everyone. Nothing here duplicates them, and nothing there should move up into this file.
 *
 * A consequence worth stating plainly: an owner passes `issue()` below on an already-issued invoice, and is
 * then refused by the service. That is the design working, not a gap. Anything reporting capabilities to a
 * client must ask the model for state *and* the gate for permission, separately — see `JournalEntryResource`.
 *
 * There is no `SalesInvoiceLinePolicy`. A line is not independently addressable — it is created, replaced or
 * destroyed as part of its invoice — so authorising one separately would invite a caller to reach for a line
 * without its document.
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

    /**
     * Committing a draft to the ledger and to the customer.
     *
     * Only a draft can be issued, and the status check is advisory — see the note above. `SalesInvoiceService`
     * re-checks it, along with the lines, the total, every account and the fiscal period.
     */
    public function issue(User $user, SalesInvoice $invoice): bool
    {
        return $invoice->isDraft()
            && $user->can('sales.invoices.issue')
            && $user->canAccessCompany($invoice->company_id);
    }

    /**
     * Reversing an issued invoice's posting.
     *
     * Separate from `issue` because the capabilities are genuinely different: raising a document a customer
     * will pay is not the same authority as undoing one already in the books. `accounting.journals.post` and
     * `.reverse` make the same distinction.
     *
     * `hasBeenIssued()` is deliberately broader than the service's rule, which permits only `Issued` — a
     * cancelled invoice returns true here. The looser test is right for a *capability*: the service owns
     * whether this particular invoice may still be cancelled, and duplicating that rule would put two copies
     * of it in the codebase for a check an owner never reaches anyway.
     */
    public function cancel(User $user, SalesInvoice $invoice): bool
    {
        return $invoice->status->hasBeenIssued()
            && $user->can('sales.invoices.cancel')
            && $user->canAccessCompany($invoice->company_id);
    }
}
