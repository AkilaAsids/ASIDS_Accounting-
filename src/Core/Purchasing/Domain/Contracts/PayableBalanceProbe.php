<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Contracts;

use Asids\Core\Purchasing\Domain\Models\Supplier;

/**
 * Answers "does the company still owe this supplier anything?".
 *
 * `SupplierService` needs that answer to enforce three rules — a supplier with an outstanding balance
 * cannot be archived, one named by any bill cannot be deleted, and one named by any bill cannot be
 * recoded — but bills do not exist until Wave 7. This interface is the seam, and `NoPayables` below it
 * reports the truth for the current schema: there is no bill table, so nobody is owed anything.
 *
 * The pattern is Sales', not an invention. `ReceivableBalanceProbe` let the customer domain enforce the
 * same rules before any invoice table existed, and Sales bound a real implementation over it in
 * Milestone 5 without a line of `CustomerService` changing.
 *
 * Building the seam now rather than later is what stops the rule being forgotten. A constraint with
 * nothing to enforce it on day one is usually a constraint that never arrives, and "we will remember to
 * block archiving once bills land" is precisely the promise that does not get kept.
 */
interface PayableBalanceProbe
{
    /**
     * What the company still owes this supplier, as a decimal string at the ledger's scale.
     *
     * A string rather than a float, for the reason `Money` exists: a balance compared with `bccomp`
     * must use the same arithmetic the ledger used to produce it.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Supplier $supplier): string;

    /**
     * Whether any bill — draft, issued or cancelled — names this supplier.
     *
     * Distinct from owing money. A supplier whose only bill was paid in full is owed nothing and still
     * cannot be deleted, because the bill is a statutory record that names them.
     */
    public function hasAnyBill(Supplier $supplier): bool;
}
