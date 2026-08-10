<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Infrastructure;

use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Models\Customer;

/**
 * The receivables probe for a schema that has no invoices yet.
 *
 * Not a stub standing in for missing work — an accurate statement of the current schema. Milestone 2
 * creates customers and no invoice table, so no customer can owe anything, and both methods report
 * exactly that.
 *
 * Milestone 5 binds an implementation that queries `sales_invoices` over this one in the container,
 * and the archive and delete rules in `CustomerService` begin to bite without those methods changing.
 */
final class NoReceivables implements ReceivableBalanceProbe
{
    /**
     * @return numeric-string
     */
    public function outstandingBalance(Customer $customer): string
    {
        return '0.0000';
    }

    public function hasAnyInvoice(Customer $customer): bool
    {
        return false;
    }
}
