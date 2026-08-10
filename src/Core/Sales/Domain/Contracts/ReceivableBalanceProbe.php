<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Contracts;

use Asids\Core\Sales\Domain\Models\Customer;

/**
 * Answers "does this customer still owe anything?".
 *
 * `CustomerService` needs that answer to enforce two rules — a customer with an outstanding balance
 * cannot be archived, and one with any invoice at all cannot be deleted — but invoices do not exist
 * until Milestone 4. This interface is the seam, and `NoReceivables` below it reports the truth for
 * the current schema: there is no invoice table, so nobody owes anything.
 *
 * The pattern is Phase 1's, not an invention. `LedgerActivityProbe` let Organization enforce "a
 * company's base currency freezes once its books have activity" before any postable table existed, and
 * Accounting bound a real implementation over it in Phase 2 without a line of those services changing.
 *
 * Building the seam now rather than later is what stops the rule being forgotten. A constraint with
 * nothing to enforce it on day one is usually a constraint that never arrives, and "we will remember
 * to block archiving once invoices land" is precisely the promise that does not get kept.
 */
interface ReceivableBalanceProbe
{
    /**
     * The customer's outstanding balance, as a decimal string at the ledger's scale.
     *
     * A string rather than a float, for the reason `Money` exists: a balance compared against a credit
     * limit must use the same arithmetic the ledger used to produce it.
     *
     * @return numeric-string
     */
    public function outstandingBalance(Customer $customer): string;

    /**
     * Whether any invoice — issued, cancelled or still draft — names this customer.
     *
     * Distinct from owing money. A customer whose only invoice was paid in full owes nothing and still
     * cannot be deleted, because the invoice is a statutory record that names them.
     */
    public function hasAnyInvoice(Customer $customer): bool;
}
