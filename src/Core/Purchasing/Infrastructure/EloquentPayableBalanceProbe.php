<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Infrastructure;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;

/**
 * The real answer to "does the company still owe this supplier anything?" — the payable-side mirror of
 * `EloquentReceivableBalanceProbe`.
 *
 * Wave 6 defined the seam and bound `NoPayables`, which truthfully reported that no bill table existed. It does
 * now, so three rules Wave 6 wrote and could not enforce start working for the first time:
 *
 *   * A supplier the company still **owes** cannot be archived. An archived supplier disappears from the screens
 *     someone would use to pay the balance, so archiving one who is still owed is how a payable gets quietly lost.
 *   * A supplier named by **any bill at all** cannot be deleted, and cannot have its code changed. The bill is a
 *     statutory record that names them, and the code appears on documents they hold.
 *
 * Nothing in `SupplierService` changed to make that happen. The binding moved, which is what the seam was for.
 *
 * THE TWO METHODS ASK DIFFERENT QUESTIONS
 * ---------------------------------------
 * Owing money is about the *present*: a paid bill is settled and a cancelled one no longer owed, so neither is a
 * balance. Being named by a bill is about the *record*: a supplier whose only bill was paid in full is owed
 * nothing and still cannot be deleted. So `outstandingBalance()` filters to outstanding bills and `hasAnyBill()`
 * filters by nothing at all — not even drafts, which count, because a draft naming a supplier is still a reason
 * not to remove the row it points at.
 *
 * WHY THE COMPANY FILTER IS NOT REDUNDANT
 * ---------------------------------------
 * Row level security scopes these queries to the tenant, and `BelongsToTenant` adds its global scope on top.
 * Neither separates two companies inside one workspace — they share a `tenant_id` — so only the explicit
 * `forCompany()` stops a supplier belonging to company A being answered with company B's bills.
 */
final class EloquentPayableBalanceProbe implements PayableBalanceProbe
{
    /**
     * What the supplier is still owed, summed from the bills that represent a live payable.
     *
     * `scopeOutstanding()` decides which those are, and is deliberately not restated here: it already excludes
     * drafts, which are not yet owed, and cancelled and paid bills, which no longer are.
     *
     * The figure comes from `amount_due` rather than `total - amount_paid`. Today they agree, because a
     * phase-scoped CHECK holds `amount_paid` at zero — but Wave 8 drops it, and at that point the stored column
     * is the one the payment allocation maintains.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Supplier $supplier): string
    {
        $sum = Bill::query()
            ->forCompany((string) $supplier->company_id)
            ->where('supplier_id', $supplier->getKey())
            ->outstanding()
            ->sum('amount_due');

        // Normalised rather than returned as the driver hands it back: an empty set sums to the integer 0, and a
        // populated one to a numeric string whose scale PostgreSQL chooses. The contract promises a decimal
        // string at the ledger's scale, and `SupplierService` compares it with `bccomp` at exactly that scale.
        return bcadd((string) $sum, '0', Money::SCALE);
    }

    /**
     * Whether any bill names this supplier, in any state.
     *
     * No status filter, and none is missing. A bill is a statutory record: paid, cancelled or still a draft, it
     * names the supplier, and the record has to outlive the relationship.
     */
    public function hasAnyBill(Supplier $supplier): bool
    {
        return Bill::query()
            ->forCompany((string) $supplier->company_id)
            ->where('supplier_id', $supplier->getKey())
            ->exists();
    }
}
