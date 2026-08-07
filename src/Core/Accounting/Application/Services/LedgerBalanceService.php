<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maintains and reads the period balance aggregates.
 *
 * Two responsibilities that must not drift apart: the writer that keeps `account_period_balances`
 * in step with the ledger, and the readers that trust it. They live together so that a change to
 * what is stored cannot miss a reader — and `recomputeFor()` is deliberately the same code path the
 * verify and rebuild commands use, so the definition of "correct" has exactly one implementation.
 */
final readonly class LedgerBalanceService
{
    /**
     * Bring an entry's accounts up to date, inside the posting transaction.
     *
     * Recomputed from the lines rather than incremented by the entry's amounts. Incrementing is
     * faster and is wrong the moment anything is retried, replayed or posted twice — a lost update
     * leaves a total that no longer matches its own lines, and nothing reports it. Recomputing one
     * account-period from an indexed aggregate costs a fraction of a millisecond and cannot drift.
     */
    public function applyEntry(JournalEntry $entry): void
    {
        $accountIds = $entry->lines()->pluck('account_id')->unique()->all();

        foreach ($accountIds as $accountId) {
            $this->recomputeFor($entry->company_id, (string) $accountId, $entry->fiscal_period_id);
        }
    }

    /**
     * Recompute one account-period from the lines. The single definition of a correct aggregate.
     *
     * @return array{debit: string, credit: string, lines: int}
     */
    public function recomputeFor(string $companyId, string $accountId, string $periodId): array
    {
        /** @var object{debit_total: string|null, credit_total: string|null, line_count: int}|null $totals */
        $totals = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)
            ->where('l.account_id', $accountId)
            ->where('e.fiscal_period_id', $periodId)
            // Drafts are excluded and reversals are not. A reversed entry and its reversal both
            // remain in the ledger and cancel; dropping either would leave the balance out by the
            // entry's amount.
            ->whereIn('e.status', ['posted', 'reversed'])
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit_total, COALESCE(SUM(l.credit), 0) as credit_total, COUNT(*) as line_count')
            ->first();

        $debit = (string) ($totals->debit_total ?? '0');
        $credit = (string) ($totals->credit_total ?? '0');
        $lineCount = (int) ($totals->line_count ?? 0);

        $tenantId = DB::table('accounts')->where('id', $accountId)->value('tenant_id');

        // Upsert rather than find-then-write: two entries posting to the same account and period in
        // parallel would otherwise both see no row and both insert, and one would lose to the unique
        // index having already done its work.
        DB::table('account_period_balances')->upsert(
            [[
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'account_id' => $accountId,
                'fiscal_period_id' => $periodId,
                'debit_total' => $debit,
                'credit_total' => $credit,
                'line_count' => $lineCount,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['account_id', 'fiscal_period_id'],
            ['debit_total', 'credit_total', 'line_count', 'updated_at'],
        );

        return ['debit' => $debit, 'credit' => $credit, 'lines' => $lineCount];
    }

    /**
     * A trial balance: every account with movement, and its closing position.
     *
     * Read from the aggregates rather than the lines, which is the whole point of maintaining them.
     * Accounts with no movement at all are omitted — a chart of eighty accounts on a month where
     * twelve were used produces a report nobody reads.
     *
     * @return list<array{
     *     account: Account,
     *     debit: Money,
     *     credit: Money,
     *     balance: Money,
     * }>
     */
    public function trialBalance(Company $company, DateRange $range): array
    {
        $currency = $company->base_currency_code;

        $rows = DB::table('account_period_balances as b')
            ->join('fiscal_periods as p', 'p.id', '=', 'b.fiscal_period_id')
            ->where('b.company_id', $company->getKey())
            ->whereDate('p.starts_on', '>=', $range->start->toDateString())
            ->whereDate('p.ends_on', '<=', $range->end->toDateString())
            ->groupBy('b.account_id')
            ->selectRaw('b.account_id, SUM(b.debit_total) as debit_total, SUM(b.credit_total) as credit_total')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<string, Account> $accounts */
        $accounts = Account::query()
            ->forCompany($company->getKey())
            ->whereIn('id', $rows->pluck('account_id')->all())
            ->get()
            ->keyBy('id')
            ->all();

        $result = [];

        foreach ($rows as $row) {
            $account = $accounts[$row->account_id] ?? null;

            if ($account === null) {
                continue;
            }

            $debit = Money::of((string) $row->debit_total, $currency);
            $credit = Money::of((string) $row->credit_total, $currency);

            $result[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                // Signed by the account's own normal balance, so an asset and a liability both read
                // positive when they are in the state their type expects.
                'balance' => Money::ofMinorUnits(
                    $account->normal_balance->signedFrom($debit->minorUnits, $credit->minorUnits),
                    $currency,
                ),
            ];
        }

        // Sorted by type then code — the order every set of books is read in, and the order the
        // balance sheet and P&L are laid out in.
        usort($result, static function (array $left, array $right): int {
            $byType = $left['account']->type->sortOrder() <=> $right['account']->type->sortOrder();

            return $byType !== 0 ? $byType : strcmp($left['account']->code, $right['account']->code);
        });

        return $result;
    }

    /**
     * Whether a trial balance ties.
     *
     * The single question that says whether the ledger is sound. If this is ever false, something has
     * bypassed both the deferred constraint trigger and the posting service, and the aggregates are
     * the least of the problem.
     *
     * @param  list<array{account: Account, debit: Money, credit: Money, balance: Money}>  $rows
     */
    public function trialBalanceTies(array $rows, string $currency): bool
    {
        $debits = array_reduce(
            $rows,
            static fn (Money $carry, array $row): Money => $carry->plus($row['debit']),
            Money::zero($currency),
        );

        $credits = array_reduce(
            $rows,
            static fn (Money $carry, array $row): Money => $carry->plus($row['credit']),
            Money::zero($currency),
        );

        return $debits->equals($credits);
    }

    /**
     * Every posted line touching one account, in date order, with a running balance.
     *
     * Read from the lines rather than the aggregates, deliberately: this report *is* the detail, and
     * an account ledger that disagreed with the journal it summarises would be worse than no report.
     * The aggregates answer "how much"; this answers "which entries".
     *
     * @return array{opening: Money, lines: list<array{
     *     entry: JournalEntry,
     *     debit: Money,
     *     credit: Money,
     *     running: Money,
     * }>, closing: Money}
     */
    public function accountLedger(Company $company, Account $account, DateRange $range): array
    {
        $currency = $company->base_currency_code;

        $opening = $this->balanceAsAt($company, $account, $range->start->subDay());

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $company->getKey())
            ->where('l.account_id', $account->getKey())
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereDate('e.entry_date', '>=', $range->start->toDateString())
            ->whereDate('e.entry_date', '<=', $range->end->toDateString())
            ->orderBy('e.entry_date')
            ->orderBy('e.number')
            ->orderBy('l.line_number')
            ->select(['l.id', 'l.debit', 'l.credit', 'l.description', 'e.id as entry_id'])
            ->get();

        /** @var array<string, JournalEntry> $entries */
        $entries = JournalEntry::query()
            ->whereIn('id', $lines->pluck('entry_id')->unique()->all())
            ->get()
            ->keyBy('id')
            ->all();

        $running = $opening;
        $result = [];

        foreach ($lines as $line) {
            $entry = $entries[$line->entry_id] ?? null;

            if ($entry === null) {
                continue;
            }

            $debit = Money::of((string) $line->debit, $currency);
            $credit = Money::of((string) $line->credit, $currency);

            $running = $running->plus(Money::ofMinorUnits(
                $account->normal_balance->signedFrom($debit->minorUnits, $credit->minorUnits),
                $currency,
            ));

            $result[] = [
                'entry' => $entry,
                'debit' => $debit,
                'credit' => $credit,
                'running' => $running,
            ];
        }

        return ['opening' => $opening, 'lines' => $result, 'closing' => $running];
    }

    /**
     * An account's balance at the end of a date, from the aggregates.
     *
     * Sums every period that ended on or before the date. A period straddling the date is included
     * in full, which is why this is only called with period boundaries — an "as at mid-month" figure
     * has to come from the lines, and `accountLedger()` computes it that way.
     */
    public function balanceAsAt(Company $company, Account $account, CarbonImmutable $date): Money
    {
        $currency = $company->base_currency_code;

        /** @var object{debit_total: string|null, credit_total: string|null}|null $totals */
        $totals = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $company->getKey())
            ->where('l.account_id', $account->getKey())
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereDate('e.entry_date', '<=', $date->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit_total, COALESCE(SUM(l.credit), 0) as credit_total')
            ->first();

        $debit = Money::of((string) ($totals->debit_total ?? '0'), $currency);
        $credit = Money::of((string) ($totals->credit_total ?? '0'), $currency);

        return Money::ofMinorUnits(
            $account->normal_balance->signedFrom($debit->minorUnits, $credit->minorUnits),
            $currency,
        );
    }

    /**
     * Every account-period whose stored totals disagree with the lines.
     *
     * The read half of `asids:ledger-verify`. Walks from the *lines* rather than from the aggregates,
     * so an account-period with lines and no aggregate row at all — the most likely drift, and the
     * one an aggregate-first walk cannot see — is reported.
     *
     * @return list<array{
     *     account_id: string,
     *     fiscal_period_id: string,
     *     stored_debit: string,
     *     stored_credit: string,
     *     actual_debit: string,
     *     actual_credit: string,
     * }>
     */
    public function drift(Company $company): array
    {
        /**
         * @var list<object{
         *     account_id: string,
         *     fiscal_period_id: string,
         *     stored_debit: string,
         *     stored_credit: string,
         *     actual_debit: string,
         *     actual_credit: string,
         * }> $rows
         */
        $rows = DB::select(<<<'SQL'
            WITH actual AS (
                SELECT
                    l.account_id,
                    e.fiscal_period_id,
                    SUM(l.debit) AS debit_total,
                    SUM(l.credit) AS credit_total
                FROM journal_lines l
                JOIN journal_entries e ON e.id = l.journal_entry_id
                WHERE l.company_id = ?
                  AND e.status IN ('posted', 'reversed')
                GROUP BY l.account_id, e.fiscal_period_id
            )
            SELECT
                COALESCE(a.account_id, b.account_id) AS account_id,
                COALESCE(a.fiscal_period_id, b.fiscal_period_id) AS fiscal_period_id,
                COALESCE(b.debit_total, 0) AS stored_debit,
                COALESCE(b.credit_total, 0) AS stored_credit,
                COALESCE(a.debit_total, 0) AS actual_debit,
                COALESCE(a.credit_total, 0) AS actual_credit
            FROM actual a
            FULL OUTER JOIN account_period_balances b
                ON b.account_id = a.account_id
               AND b.fiscal_period_id = a.fiscal_period_id
               AND b.company_id = ?
            WHERE COALESCE(b.debit_total, 0) <> COALESCE(a.debit_total, 0)
               OR COALESCE(b.credit_total, 0) <> COALESCE(a.credit_total, 0)
        SQL, [$company->getKey(), $company->getKey()]);

        return array_map(
            /**
             * @param  object{account_id: string, fiscal_period_id: string, stored_debit: string, stored_credit: string, actual_debit: string, actual_credit: string}  $row
             * @return array{account_id: string, fiscal_period_id: string, stored_debit: string, stored_credit: string, actual_debit: string, actual_credit: string}
             */
            static fn (object $row): array => [
                'account_id' => (string) $row->account_id,
                'fiscal_period_id' => (string) $row->fiscal_period_id,
                'stored_debit' => (string) $row->stored_debit,
                'stored_credit' => (string) $row->stored_credit,
                'actual_debit' => (string) $row->actual_debit,
                'actual_credit' => (string) $row->actual_credit,
            ],
            $rows,
        );
    }

    /**
     * Discard and recompute a company's aggregates from the lines.
     *
     * The repair half of the pair, behind `asids:ledger-rebuild --confirm`. Scoped to one period when
     * given one, so a repair can be narrow rather than rewriting seven years to fix one month.
     *
     * @return int The number of account-periods written.
     */
    public function rebuild(Company $company, ?FiscalPeriod $period = null): int
    {
        return DB::transaction(function () use ($company, $period): int {
            $stale = DB::table('account_period_balances')->where('company_id', $company->getKey());

            if ($period !== null) {
                $stale->where('fiscal_period_id', $period->getKey());
            }

            // Deleted first, so an account-period that no longer has any lines does not survive as a
            // stale row. Incrementally correcting could never remove one.
            $stale->delete();

            $combinations = DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.company_id', $company->getKey())
                ->whereIn('e.status', ['posted', 'reversed'])
                ->when(
                    $period instanceof FiscalPeriod,
                    static fn ($query) => $query->where('e.fiscal_period_id', $period?->getKey()),
                )
                ->distinct()
                ->select(['l.account_id', 'e.fiscal_period_id'])
                ->get();

            foreach ($combinations as $combination) {
                $this->recomputeFor(
                    $company->getKey(),
                    (string) $combination->account_id,
                    (string) $combination->fiscal_period_id,
                );
            }

            return $combinations->count();
        });
    }
}
