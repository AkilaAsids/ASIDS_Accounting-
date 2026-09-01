<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Infrastructure;

use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Supplier;

/**
 * The payables probe for a schema that has no bills yet.
 *
 * Not a stub standing in for missing work — an accurate statement of the current schema. Wave 6 creates
 * suppliers and no bill table, so no supplier can be owed anything, and both methods report exactly
 * that.
 *
 * Wave 7 binds an implementation that queries the bills table over this one in the container, and the
 * archive, delete and code-lock rules in `SupplierService` begin to bite without those methods
 * changing. `NoPayables` is kept rather than deleted: it is the honest answer for any context with no
 * bill table, and a test wanting "this supplier is owed nothing" binds it directly.
 */
final class NoPayables implements PayableBalanceProbe
{
    /**
     * @return numeric-string
     */
    public function outstandingBalance(Supplier $supplier): string
    {
        return '0.0000';
    }

    public function hasAnyBill(Supplier $supplier): bool
    {
        return false;
    }
}
