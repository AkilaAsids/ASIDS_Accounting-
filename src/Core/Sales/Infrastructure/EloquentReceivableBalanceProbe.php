<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Infrastructure;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;

/**
 * The real answer to "does this customer still owe anything?".
 *
 * Milestone 2 defined this seam and bound `NoReceivables`, which truthfully reported that no invoice table
 * existed. It does now, so two rules Milestone 2 wrote and could not enforce start working for the first
 * time:
 *
 *   * A customer with an **outstanding balance** cannot be archived. An archived customer disappears from
 *     the screens someone would use to chase the debt, so archiving one who still owes is how a receivable
 *     gets quietly lost.
 *   * A customer named by **any invoice at all** cannot be deleted, and cannot have its code changed. The
 *     invoice is a statutory record that names them, and the code appears on the document they hold.
 *
 * Nothing in `CustomerService` changed to make that happen. The binding moved, which is what the seam was
 * for — the same way `EloquentLedgerActivityProbe` activated Phase 1's currency and calendar rules.
 *
 * THE TWO METHODS ASK DIFFERENT QUESTIONS
 * ---------------------------------------
 * Deliberately, and the difference is the whole reason there are two. Owing money is about the *present*:
 * a paid invoice is settled and a cancelled one was reversed, so neither is a balance. Being named by an
 * invoice is about the *record*: a customer whose only invoice was paid in full owes nothing and still
 * cannot be deleted, because the document exists and names them.
 *
 * So `outstandingBalance()` filters to collectable invoices and `hasAnyInvoice()` filters by nothing at all
 * — not even drafts, which count, because a draft naming a customer is still a reason not to remove the row
 * it points at.
 *
 * WHY THE COMPANY FILTER IS NOT REDUNDANT
 * ---------------------------------------
 * Row level security scopes these queries to the tenant, and `BelongsToTenant` adds its global scope on top.
 * Neither separates two companies inside one workspace — they share a `tenant_id` — so a customer belonging
 * to company A would otherwise be answered with company B's invoices. Only the explicit `forCompany()` stops
 * that, and it is the same reason every other cross-model query in this module carries one.
 */
final class EloquentReceivableBalanceProbe implements ReceivableBalanceProbe
{
    /**
     * What the customer still owes, summed from the invoices that represent a live receivable.
     *
     * `scopeCollectable()` decides which those are, and is deliberately not restated here: it already
     * excludes drafts, which are not yet owed, and cancelled and paid invoices, which no longer are. A copy
     * of that list in this file would be a second definition of "collectable" free to drift from the first.
     *
     * The figure comes from `amount_due` rather than `total - amount_paid`. Today they agree, because a
     * phase-scoped CHECK holds `amount_paid` at zero — but Phase 4 drops it, and at that point the stored
     * column is the one the payment allocation maintains. Subtracting here would quietly become a second
     * implementation of a calculation Phase 4 owns.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Customer $customer): string
    {
        $sum = SalesInvoice::query()
            ->forCompany((string) $customer->company_id)
            ->where('customer_id', $customer->getKey())
            ->collectable()
            ->sum('amount_due');

        // Normalised rather than returned as the driver hands it back: an empty set sums to the integer 0,
        // and a populated one to a numeric string whose scale PostgreSQL chooses. The contract promises a
        // decimal string at the ledger's scale, and `CustomerService` compares it with `bccomp` at exactly
        // that scale.
        return bcadd((string) $sum, '0', Money::SCALE);
    }

    /**
     * Whether any invoice names this customer, in any state.
     *
     * No status filter, and none is missing. An invoice is a statutory record: paid, cancelled or still a
     * draft, it names the customer, and the record has to outlive the relationship.
     */
    public function hasAnyInvoice(Customer $customer): bool
    {
        return SalesInvoice::query()
            ->forCompany((string) $customer->company_id)
            ->where('customer_id', $customer->getKey())
            ->exists();
    }
}
