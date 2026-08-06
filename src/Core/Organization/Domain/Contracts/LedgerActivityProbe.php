<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Contracts;

use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;

/**
 * Answers "has anything been posted against this entity?".
 *
 * The Organization module needs this answer to enforce two rules — a company's base
 * currency and fiscal calendar become immutable once its books have activity, and an
 * entity with activity can be archived but never deleted — yet it must not know anything
 * about journal entries, invoices or stock movements, which belong to later phases.
 *
 * This interface is that seam. The Accounting module will bind an implementation that
 * queries the ledger; until then NoLedgerActivity reports the truth, which is that no
 * postable table exists.
 */
interface LedgerActivityProbe
{
    /**
     * Whether any transaction has ever been posted against the company.
     */
    public function companyHasActivity(Company $company): bool;

    /**
     * Whether any transaction references the branch as a dimension.
     */
    public function branchHasActivity(Branch $branch): bool;

    /**
     * The earliest posted transaction date, used to reject a fiscal calendar change that
     * would move an existing transaction into a different period.
     */
    public function earliestActivityDate(Company $company): ?\DateTimeImmutable;
}
