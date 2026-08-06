<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Infrastructure;

use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use DateTimeImmutable;

/**
 * The ledger-activity probe for a platform that has no ledger yet.
 *
 * This is not a stub standing in for missing work — it is an accurate statement of the
 * current schema. Phase 1 provisions companies and branches but defines no postable
 * table, so no company can have activity, and every method here reports exactly that.
 *
 * When the Accounting module lands it binds its own implementation over this one in the
 * container, and the immutability rules in CompanyService and BranchService begin to bite
 * without a line of those services changing. Building the seam now rather than later is
 * what keeps `base_currency_code` from quietly becoming editable forever, which is the
 * usual fate of a rule that has nothing to enforce it on day one.
 */
final class NoLedgerActivity implements LedgerActivityProbe
{
    public function companyHasActivity(Company $company): bool
    {
        return false;
    }

    public function branchHasActivity(Branch $branch): bool
    {
        return false;
    }

    public function earliestActivityDate(Company $company): ?DateTimeImmutable
    {
        return null;
    }
}
