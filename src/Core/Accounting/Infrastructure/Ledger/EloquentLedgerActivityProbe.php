<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Infrastructure\Ledger;

use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The real answer to "has anything been posted against this?".
 *
 * Phase 1 defined this seam and bound `NoLedgerActivity`, which truthfully reported that no postable
 * table existed. It now exists, so two rules Phase 1 wrote and could not enforce start working for
 * the first time:
 *
 *   * A company's **base currency** becomes immutable once its books have activity. Changing it does
 *     not convert anything — it silently reinterprets every historical amount.
 *   * A company's **fiscal calendar** becomes immutable for the same reason: moving the year start
 *     moves existing entries into different periods, changing figures that have been filed.
 *
 * Nothing in Organization changed to make that happen. The binding moved, which is what the seam was
 * for.
 *
 * Drafts do not count. An unposted entry can be edited or discarded, so a company with nothing but
 * drafts genuinely can still correct its currency — and telling a customer on their first afternoon
 * that a typo is permanent because of an entry they have not committed would be wrong.
 */
final class EloquentLedgerActivityProbe implements LedgerActivityProbe
{
    public function companyHasActivity(Company $company): bool
    {
        return JournalLine::query()
            ->forCompany($company->getKey())
            ->affectingBalances()
            ->exists();
    }

    public function branchHasActivity(Branch $branch): bool
    {
        return JournalLine::query()
            ->where('branch_id', $branch->getKey())
            ->affectingBalances()
            ->exists();
    }

    /**
     * The earliest posted entry date, used to refuse a fiscal calendar change that would move an
     * existing transaction into a different period.
     *
     * Read from `journal_entries.entry_date` rather than a line's timestamp: the entry date is the
     * accounting date, and it is what decides which period a transaction belongs to. `created_at`
     * would answer a different question — when it was typed — and give a different period for
     * anything backdated.
     */
    public function earliestActivityDate(Company $company): ?DateTimeImmutable
    {
        $earliest = DB::table('journal_entries')
            ->where('company_id', $company->getKey())
            ->whereIn('status', ['posted', 'reversed'])
            ->min('entry_date');

        return $earliest === null ? null : new DateTimeImmutable((string) $earliest);
    }
}
