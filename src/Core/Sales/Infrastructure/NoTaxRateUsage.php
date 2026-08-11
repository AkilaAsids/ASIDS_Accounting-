<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Infrastructure;

use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Models\TaxCode;

/**
 * The rate-usage probe for a schema with no accounting documents that carry tax.
 *
 * Not a placeholder standing in for missing work — an accurate statement of the current schema.
 * Milestone 3 creates tax codes and no invoice table, so no rate can have been applied to anything, and
 * this reports exactly that.
 *
 * Milestone 4 binds an implementation that queries the documents over this one in the container, and the
 * immutability rules in `TaxCodeService` begin to bite without a line of that service changing.
 */
final class NoTaxRateUsage implements TaxRateUsageProbe
{
    public function hasBeenApplied(TaxCode $taxCode): bool
    {
        return false;
    }
}
