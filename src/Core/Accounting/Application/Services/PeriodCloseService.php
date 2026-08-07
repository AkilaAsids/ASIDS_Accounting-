<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Events\PeriodClosed;
use Asids\Core\Accounting\Domain\Events\PeriodReopened;
use Asids\Core\Accounting\Domain\Events\YearClosed;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Closing and reopening periods, and closing a year.
 *
 * Closing a period is a soft lock: it stops new postings so that figures already reported to a bank,
 * a board or a tax authority cannot change underneath them. It is reversible by someone with the
 * authority, and both directions are audited — a period that could never be reopened would mean a
 * genuine error discovered in month three is uncorrectable, which is worse than the risk closing
 * protects against.
 *
 * Closing a *year* is different and is the only routine in the module that computes an amount rather
 * than recording one. Income and expense accounts measure a single year's trading; carrying them
 * forward would accumulate since the company was founded. So the year-end entry moves every one of
 * them to zero and puts the net difference — the year's profit or loss — into retained earnings.
 *
 * It does that with an ordinary reversible journal entry rather than by mutating anything. A year
 * closed in error is corrected by reversing that entry, which is the same remedy as every other
 * mistake in the ledger.
 */
final readonly class PeriodCloseService
{
    public function __construct(
        private PostingService $posting,
        private ChartOfAccountsService $chart,
        private ChartTemplateService $template,
        private LedgerBalanceService $balances,
    ) {}

    /**
     * Close a period to further postings.
     */
    public function close(FiscalPeriod $period, ?User $actor = null): FiscalPeriod
    {
        if ($period->status === PeriodStatus::Closed) {
            return $period;
        }

        if ($period->status === PeriodStatus::Locked) {
            throw BusinessRuleViolation::make(
                code: 'period-locked',
                message: sprintf('%s is locked and cannot be closed again. Unlock it first.', $period->label),
            );
        }

        // Earlier periods first, or the sequence stops meaning anything: a closed March with an open
        // February lets someone post into February and change the year-to-date figures that March's
        // close was supposed to fix.
        $earlierOpen = FiscalPeriod::query()
            ->forCompany($period->company_id)
            ->where('starts_on', '<', $period->starts_on->toDateString())
            ->where('status', PeriodStatus::Open->value)
            ->orderBy('starts_on')
            ->first();

        if ($earlierOpen !== null) {
            throw BusinessRuleViolation::make(
                code: 'earlier-period-still-open',
                message: sprintf(
                    'Close %s first. Periods are closed in order, so that a closed period cannot be affected by a later posting into an earlier one.',
                    $earlierOpen->label,
                ),
                context: ['earliest_open_period' => $earlierOpen->label],
            );
        }

        $draftCount = JournalEntry::query()
            ->forCompany($period->company_id)
            ->where('fiscal_period_id', $period->getKey())
            ->drafts()
            ->count();

        if ($draftCount > 0) {
            // Refused rather than discarding them. A draft in a closed period can never be posted, so
            // it is silently dead work — and the person who wrote it should decide whether it matters.
            throw BusinessRuleViolation::make(
                code: 'period-has-drafts',
                message: sprintf(
                    '%s has %d unposted draft %s. Post or delete them first — a draft in a closed period can never be posted.',
                    $period->label,
                    $draftCount,
                    $draftCount === 1 ? 'entry' : 'entries',
                ),
                context: ['drafts' => $draftCount],
            );
        }

        $closed = DB::transaction(function () use ($period, $actor): FiscalPeriod {
            $period->status = PeriodStatus::Closed;
            $period->closed_at = now();
            $period->closed_by_id = $actor?->getKey();
            $period->save();

            return $period;
        });

        PeriodClosed::dispatch($closed, $actor);

        return $closed;
    }

    /**
     * Reopen a closed period.
     *
     * Privileged and audited. The reason is required, not optional: reopening a period changes figures
     * that may already have been filed, and "why" is the first question an auditor asks.
     */
    public function reopen(FiscalPeriod $period, string $reason, ?User $actor = null): FiscalPeriod
    {
        if ($period->status === PeriodStatus::Open) {
            return $period;
        }

        if ($period->status === PeriodStatus::Locked) {
            throw BusinessRuleViolation::make(
                code: 'period-locked',
                message: sprintf(
                    '%s is locked, which is stronger than closed — its year has been closed or its figures filed. Reverse the year-end close first.',
                    $period->label,
                ),
            );
        }

        if (trim($reason) === '') {
            throw BusinessRuleViolation::make(
                code: 'reopen-reason-required',
                message: 'Reopening a closed period needs a reason. It will be recorded against the period.',
            );
        }

        $reopened = DB::transaction(function () use ($period, $reason, $actor): FiscalPeriod {
            $period->status = PeriodStatus::Open;
            $period->closed_at = null;
            $period->closed_by_id = null;
            $period->reopened_at = now();
            $period->reopened_by_id = $actor?->getKey();
            $period->reopen_reason = $reason;
            $period->save();

            return $period;
        });

        PeriodReopened::dispatch($reopened, $reason, $actor);

        return $reopened;
    }

    /**
     * Close a fiscal year: zero the income and expense accounts into retained earnings.
     *
     * The only routine here that computes an amount. Everything it writes is an ordinary journal
     * entry, so the whole operation is reversible by reversing that entry — which is what makes
     * closing a year safe to do on the accountant's judgement rather than requiring certainty.
     */
    public function closeYear(FiscalYear $year, ?User $actor = null): ?JournalEntry
    {
        $company = $year->company;

        if ($year->isClosed()) {
            throw BusinessRuleViolation::make(
                code: 'year-already-closed',
                message: sprintf('%s has already been closed.', $year->label),
            );
        }

        $openPeriod = $year->periods()
            ->where('status', PeriodStatus::Open->value)
            ->reorder('starts_on')
            ->first();

        if ($openPeriod !== null) {
            throw BusinessRuleViolation::make(
                code: 'year-has-open-periods',
                message: sprintf(
                    'Every period in %s must be closed before the year can be. %s is still open.',
                    $year->label,
                    $openPeriod->label,
                ),
                context: ['open_period' => $openPeriod->label],
            );
        }

        $this->template->ensureSystemAccounts($company);

        $retained = $this->chart->systemAccount($company, Account::RETAINED_EARNINGS);

        if ($retained === null) {
            throw BusinessRuleViolation::make(
                code: 'no-retained-earnings-account',
                message: 'This company has no Retained Earnings account, so the year cannot be closed.',
            );
        }

        $currency = $company->base_currency_code;
        $range = DateRange::between($year->starts_on, $year->ends_on);

        // Every temporary account with a balance, and its closing figure.
        $trial = $this->balances->trialBalance($company, $range);

        $lines = [];
        $netInDebitTerms = Money::zero($currency);

        foreach ($trial as $row) {
            $account = $row['account'];

            if ($account->type->isPermanent()) {
                // Balance sheet accounts carry forward. Zeroing them would erase the company's
                // position, not its trading.
                continue;
            }

            $debits = $row['debit'];
            $credits = $row['credit'];

            $movement = Money::ofMinorUnits($debits->minorUnits - $credits->minorUnits, $currency);

            if ($movement->isZero()) {
                continue;
            }

            // The closing line is the opposite of the account's own balance, which is what takes it to
            // zero. An expense with a net debit is credited; income with a net credit is debited.
            $lines[] = new JournalLineData(
                accountId: $account->getKey(),
                debit: $movement->isNegative() ? $movement->absolute() : null,
                credit: $movement->isNegative() ? null : $movement->absolute(),
                description: sprintf('Year-end close of %s', $account->code),
            );

            $netInDebitTerms = $netInDebitTerms->plus($movement);
        }

        if ($lines === []) {
            // A year with no trading closes without an entry. Writing a zero entry would be noise in
            // the ledger and would still have to balance against nothing.
            return DB::transaction(function () use ($year, $actor): ?JournalEntry {
                $this->markClosed($year, null, $actor);

                return null;
            });
        }

        // Expenses exceeding income is a loss: the closing lines net to a credit, and retained
        // earnings is debited. The sign carries that without a special case.
        $lines[] = new JournalLineData(
            accountId: $retained->getKey(),
            debit: $netInDebitTerms->isNegative() ? null : $netInDebitTerms,
            credit: $netInDebitTerms->isNegative() ? $netInDebitTerms->absolute() : null,
            description: 'Net result for '.$year->label,
        );

        return DB::transaction(function () use ($company, $year, $lines, $actor): JournalEntry {
            // Dated the last day of the year, and posted into its final period — which the close has
            // to reopen momentarily, because a closed period refuses postings. Reopened and reclosed
            // inside the same transaction so no window exists where anything else could post.
            // `reorder`, not `orderByDesc`. The relation applies its own ordering by sequence, and an
            // appended sort becomes a *secondary* one — so this quietly returned January and posted the
            // year-end entry into the first month of the year.
            $finalPeriod = $year->periods()->reorder('ends_on', 'desc')->firstOrFail();

            // Momentarily reopened so the closing entry can post — a closed period refuses postings,
            // and the year-end entry belongs in the year it closes rather than the next one. Both
            // columns move together because `fiscal_periods_closed_check` requires it: a period that
            // is open has no closing timestamp, and one that is closed has one.
            $wasStatus = $finalPeriod->status;
            $wasClosedAt = $finalPeriod->closed_at;

            $finalPeriod->status = PeriodStatus::Open;
            $finalPeriod->closed_at = null;
            $finalPeriod->save();

            try {
                $entry = $this->posting->postNew($company, new JournalEntryData(
                    entryDate: $year->ends_on,
                    description: sprintf('Year-end close: %s', $year->label),
                    lines: $lines,
                    reference: $year->label,
                    documentType: DocumentType::YearEndClose,
                ), $actor);
            } finally {
                // Restored in a `finally` so a failed posting cannot leave the year's final period
                // open — the window is inside one transaction either way, but an exception escaping
                // with the period reopened would be a silent hole in the close.
                $finalPeriod->status = $wasStatus;
                $finalPeriod->closed_at = $wasClosedAt;
                $finalPeriod->save();
            }

            $this->markClosed($year, $entry, $actor);

            return $entry;
        });
    }

    /**
     * The year's result without closing it.
     *
     * What the close *would* post, so an accountant can see the figure before committing to it.
     * Positive is a profit.
     */
    public function netResultFor(FiscalYear $year): Money
    {
        $company = $year->company;
        $currency = $company->base_currency_code;

        $trial = $this->balances->trialBalance($company, DateRange::between($year->starts_on, $year->ends_on));

        $result = Money::zero($currency);

        foreach ($trial as $row) {
            if ($row['account']->type === AccountType::Income) {
                $result = $result->plus($row['balance']);
            }

            if ($row['account']->type === AccountType::Expense) {
                $result = $result->minus($row['balance']);
            }
        }

        return $result;
    }

    /**
     * Marks the year closed and locks its periods.
     *
     * Locked rather than closed: a closed period can be reopened by a controller, and a period whose
     * year has been closed must not be, because reopening it would leave retained earnings holding a
     * figure that no longer matches the trading it summarises. Reversing the year-end entry is the
     * documented route back.
     */
    private function markClosed(FiscalYear $year, ?JournalEntry $entry, ?User $actor): void
    {
        $year->is_closed = true;
        $year->closed_at = now();
        $year->closed_by_id = $actor?->getKey();
        $year->closing_entry_id = $entry?->getKey();
        $year->save();

        // `closed_at` is coalesced rather than assumed: every period reaching here is already closed
        // and has one, but the constraint requires it for the locked state too, and a mass update
        // that violated it would fail the whole close for a reason unrelated to the year's figures.
        $year->periods()->update([
            'status' => PeriodStatus::Locked->value,
            'closed_at' => DB::raw('COALESCE(closed_at, now())'),
            'updated_at' => now(),
        ]);

        YearClosed::dispatch($year, $entry, $actor);
    }
}
