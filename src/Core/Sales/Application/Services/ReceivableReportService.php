<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What customers owe, and whether the ledger agrees.
 *
 * Milestone 7's reporting side. Built to the shape `LedgerBalanceService` established rather than a new one:
 * a `Company` first, plain arrays out, `Money` all the way through, and the conversion to decimal strings
 * left to whatever eventually presents them. There are no report DTOs in this codebase and this is not the
 * place to introduce the first.
 *
 * ONE SOURCE FOR "WHAT IS STILL OWED"
 * -----------------------------------
 * Every method here filters through `SalesInvoice::scopeCollectable()` and reads `amount_due`. Neither is
 * restated. The scope already knows that a draft is not yet owed and that a cancelled or paid invoice no
 * longer is; a second copy of that list in this file would be free to drift from it, and the report that
 * drifted would be the one nobody checked.
 *
 * `amount_due` rather than `total - amount_paid` for the same reason the receivables probe reads it: they
 * agree only because a phase-scoped CHECK pins `amount_paid` at zero, and Phase 4 drops it. At that point the
 * stored column is what payment allocation maintains, and subtracting here would be a second implementation
 * of arithmetic that phase owns.
 *
 * CANCELLATION IS EXCLUDED BY STATUS, NEVER BY AMOUNT
 * --------------------------------------------------
 * Worth stating because the obvious shortcut is wrong. Cancelling does not zero `amount_due` — the CHECK
 * holds it at `total - amount_paid` — so a cancelled invoice still carries its full figure. Only the status
 * filter keeps it out. A report that summed `amount_due` without `collectable()` would count every cancelled
 * invoice ever raised.
 *
 * WHY THERE IS NO "AS AT" DATE ON THE BALANCE
 * ------------------------------------------
 * `amount_due` is current state and no payment history exists yet, so there is nothing from which to
 * reconstruct what a customer owed on some past date. The balance is a live snapshot and says so in its
 * signature. Ageing takes an explicit `$asOf` because the *buckets* are a function of a date even though the
 * amounts are current — which is a different thing, and the distinction matters when Phase 4 makes real
 * history available.
 */
final readonly class ReceivableReportService
{
    public function __construct(private LedgerBalanceService $balances) {}

    /**
     * What each customer currently owes.
     *
     * Aggregated in SQL and sorted here. The sort cannot go in the query without joining `customers` for the
     * tie-break, and the set being sorted is one company's customers with an open balance — small enough that
     * the join costs more than it saves.
     *
     * Sorted by amount descending, then customer code, so the largest debt leads and two equal balances have
     * a stable order rather than whatever the database happened to return.
     *
     * Customers with nothing outstanding do not appear. A receivables report listing everybody who owes
     * nothing is a customer list.
     *
     * @return list<array{customer: Customer, outstanding: Money, invoice_count: int}>
     */
    public function outstandingBalance(Company $company): array
    {
        $currency = $company->base_currency_code;

        /** @var Collection<int, object{customer_id: string, outstanding: string, invoice_count: int}> $aggregates */
        $aggregates = SalesInvoice::query()
            ->forCompany((string) $company->getKey())
            ->collectable()
            ->groupBy('customer_id')
            // Excluded in SQL rather than filtered afterwards, so a company with thousands of settled
            // customers does not transfer them all to be discarded.
            ->havingRaw('SUM(amount_due) > 0')
            ->selectRaw('customer_id, SUM(amount_due) AS outstanding, COUNT(*) AS invoice_count')
            // Dropped to the query builder for the aggregate. An Eloquent `get()` would hydrate
            // `SalesInvoice` models carrying two columns no invoice has, which is a lie about the type even
            // where it happens to work. `toBase()` applies the tenant global scope first, so isolation is
            // unaffected.
            ->toBase()
            ->get();

        if ($aggregates->isEmpty()) {
            return [];
        }

        /** @var array<string, Customer> $customers */
        $customers = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereIn('id', $aggregates->pluck('customer_id')->all())
            ->get()
            ->keyBy('id')
            ->all();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            /** @var string $customerId */
            $customerId = $aggregate->customer_id;
            $customer = $customers[$customerId] ?? null;

            // Absent only if the customer was hard-deleted while owing, which `CustomerService` refuses and
            // the receivables probe now enforces. Skipped rather than crashing the whole report over one row.
            if ($customer === null) {
                continue;
            }

            $rows[] = [
                'customer' => $customer,
                'outstanding' => Money::of((string) $aggregate->outstanding, $currency),
                'invoice_count' => (int) $aggregate->invoice_count,
            ];
        }

        return $this->sortedByAmountThenCode($rows, 'outstanding');
    }

    /**
     * What each customer owes, split by how overdue it is.
     *
     * Ageing runs from `due_date`, not `invoice_date`, and the domain model is what decides that:
     * `Customer::payment_terms_days` exists and `dueDateFor()` derives the due date from it when the draft is
     * written, so `due_date` is the date the business itself committed to. Ageing from the invoice date would
     * report every invoice as overdue from the day it was raised, which for thirty-day terms is wrong for a
     * month.
     *
     * `$asOf` is required rather than defaulted to today. A report aged on an implicit "now" cannot be
     * reproduced, and a printed copy could never be reconciled to a later run.
     *
     * THE BUCKETS, AND WHERE THEIR EDGES FALL
     * ---------------------------------------
     * Days are counted as `$asOf - due_date`, so positive means overdue. PostgreSQL subtracts two `date`
     * columns as whole days, which is why the arithmetic is in SQL rather than in PHP: no timezone, no partial
     * day, no drift between the two.
     *
     *   not_yet_due    days < 0        due tomorrow or later
     *   days_0_30      0 … 30          due today counts as 0 days overdue and lands here
     *   days_31_60     31 … 60
     *   days_61_90     61 … 90
     *   days_over_90   days > 90
     *
     * Every band is inclusive at both ends, so an invoice falls in exactly one. A future-dated invoice is
     * `not_yet_due` rather than excluded — it is a real receivable that simply is not late.
     *
     * TOTALS ARE SUMMED FROM THE ROWS
     * -------------------------------
     * Not computed by a second query. The report promises that the totals equal the sum of its rows, and
     * summing the rows makes that true by construction rather than by two queries happening to agree.
     *
     * @return array{
     *     rows: list<array{
     *         customer: Customer,
     *         not_yet_due: Money,
     *         days_0_30: Money,
     *         days_31_60: Money,
     *         days_61_90: Money,
     *         days_over_90: Money,
     *         total: Money,
     *     }>,
     *     totals: array{
     *         not_yet_due: Money,
     *         days_0_30: Money,
     *         days_31_60: Money,
     *         days_61_90: Money,
     *         days_over_90: Money,
     *         total: Money,
     *     },
     *     as_of: CarbonImmutable,
     * }
     */
    public function agedReceivables(Company $company, CarbonImmutable $asOf): array
    {
        $currency = $company->base_currency_code;
        $cutoff = $asOf->toDateString();

        // One bucket per CASE, aggregated per customer in a single pass. Bucketing in PHP would mean reading
        // every open invoice into memory to add them up again.
        $buckets = [
            'not_yet_due' => 'days < 0',
            'days_0_30' => 'days BETWEEN 0 AND 30',
            'days_31_60' => 'days BETWEEN 31 AND 60',
            'days_61_90' => 'days BETWEEN 61 AND 90',
            'days_over_90' => 'days > 90',
        ];

        $selects = ['customer_id'];
        $bindings = [];

        foreach ($buckets as $name => $condition) {
            // `?::date - due_date`: positive when the invoice was due before the cutoff.
            $days = str_replace('days', '(?::date - due_date)', $condition);
            $selects[] = "SUM(CASE WHEN {$days} THEN amount_due ELSE 0 END) AS {$name}";
            $bindings[] = $cutoff;
        }

        /** @var Collection<int, object{
         *     customer_id: string,
         *     not_yet_due: string,
         *     days_0_30: string,
         *     days_31_60: string,
         *     days_61_90: string,
         *     days_over_90: string,
         * }> $aggregates */
        $aggregates = SalesInvoice::query()
            ->forCompany((string) $company->getKey())
            ->collectable()
            ->groupBy('customer_id')
            ->havingRaw('SUM(amount_due) > 0')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->get();

        $totals = [
            'not_yet_due' => Money::zero($currency),
            'days_0_30' => Money::zero($currency),
            'days_31_60' => Money::zero($currency),
            'days_61_90' => Money::zero($currency),
            'days_over_90' => Money::zero($currency),
            'total' => Money::zero($currency),
        ];

        if ($aggregates->isEmpty()) {
            return ['rows' => [], 'totals' => $totals, 'as_of' => $asOf];
        }

        /** @var array<string, Customer> $customers */
        $customers = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereIn('id', $aggregates->pluck('customer_id')->all())
            ->get()
            ->keyBy('id')
            ->all();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            $customer = $customers[(string) $aggregate->customer_id] ?? null;

            if ($customer === null) {
                continue;
            }

            $notYetDue = Money::of((string) $aggregate->not_yet_due, $currency);
            $days0to30 = Money::of((string) $aggregate->days_0_30, $currency);
            $days31to60 = Money::of((string) $aggregate->days_31_60, $currency);
            $days61to90 = Money::of((string) $aggregate->days_61_90, $currency);
            $daysOver90 = Money::of((string) $aggregate->days_over_90, $currency);

            // The customer's total is the sum of their own five buckets, which is what makes the documented
            // invariant hold by construction rather than being asserted and hoped for.
            $rowTotal = $notYetDue
                ->plus($days0to30)
                ->plus($days31to60)
                ->plus($days61to90)
                ->plus($daysOver90);

            $rows[] = [
                'customer' => $customer,
                'not_yet_due' => $notYetDue,
                'days_0_30' => $days0to30,
                'days_31_60' => $days31to60,
                'days_61_90' => $days61to90,
                'days_over_90' => $daysOver90,
                'total' => $rowTotal,
            ];

            $totals['not_yet_due'] = $totals['not_yet_due']->plus($notYetDue);
            $totals['days_0_30'] = $totals['days_0_30']->plus($days0to30);
            $totals['days_31_60'] = $totals['days_31_60']->plus($days31to60);
            $totals['days_61_90'] = $totals['days_61_90']->plus($days61to90);
            $totals['days_over_90'] = $totals['days_over_90']->plus($daysOver90);
            $totals['total'] = $totals['total']->plus($rowTotal);
        }

        return [
            'rows' => $this->sortedByAmountThenCode($rows, 'total'),
            'totals' => $totals,
            'as_of' => $asOf,
        ];
    }

    /**
     * Whether the sales ledger and the general ledger agree about receivables.
     *
     * The subledger side sums what the invoices say is owed. The general ledger side asks
     * `LedgerBalanceService::balanceAsAt()` what the receivable accounts hold. They should be equal, and any
     * difference is something that reached the AR account without going through an invoice — a manual journal,
     * most often, which is exactly what this report exists to surface.
     *
     * HOW AN INVOICE'S RECEIVABLE ACCOUNT IS IDENTIFIED
     * ------------------------------------------------
     * From the posting, never from the customer. `customer.receivable_account_id` is mutable: repoint a
     * customer after their invoices were issued and their current setting no longer describes where those
     * invoices posted. Grouping by it would move old balances to the new account while the ledger kept them in
     * the old one, showing two equal and opposite differences that cancel in the total — a discrepancy the
     * report would create rather than find.
     *
     * The receivable line is **line number 1** of the invoice's journal entry, and that is a structural fact
     * rather than a guess. Four properties make it so:
     *
     *   1. `InvoicePostingMap::for()` returns `[...receivableLines, ...revenueLines, ...taxLines]` — the
     *      receivable line is first by construction.
     *   2. `receivableLines()` returns exactly one line, and returns none only for a zero total, which
     *      `issue()` refuses outright (decision B4).
     *   3. `JournalService::writeLines()` numbers lines from 1 in array order.
     *   4. `journal_lines_immutable` refuses any update or delete once the entry is posted, so the number
     *      cannot drift afterwards.
     *
     * Note what is *not* used: "the debit line". `revenueLines()` and `taxLines()` flip to the debit side for a
     * net-negative group, so an entry can carry more than one debit and "the debit" would not identify
     * anything. The line number does.
     *
     * The coupling this creates is real and worth naming: reorder the posting map and this report
     * misattributes silently. `ArControlReconciliationTest` pins the ordering directly for that reason, so the
     * map cannot be reordered without a test failing first.
     *
     * WHICH ACCOUNTS APPEAR
     * ---------------------
     * Every account any invoice has ever posted its receivable line to, plus the company's system
     * `trade_receivables` account. Derived from the ledger, so an account that used to receive invoices and no
     * longer has open ones still gets a row — which matters, because a stranded GL balance on an abandoned AR
     * account is precisely the thing that would otherwise go unnoticed.
     *
     * WHY THERE IS NO `$asOf` PARAMETER
     * --------------------------------
     * Because the report cannot honour one. `balanceAsAt()` is date-addressable, but the subledger side reads
     * current `status` and current `amount_due` and there is no invoice history to reconstruct either. Aged as
     * at a past date the two halves would answer different questions: an invoice issued in June and cancelled
     * in August reconciled as at July shows the receivable outstanding in the ledger — correctly, the reversal
     * had not happened — and excluded from the subledger, because its status is *now* cancelled.
     *
     * Accepting a date would promise something the data cannot support, so the signature does not offer one.
     * `as_of` is reported so a printed copy carries the day it was produced. When Phase 4 brings real payment
     * history, a dated variant becomes possible.
     *
     * @return array{
     *     rows: list<array{
     *         account: Account,
     *         subledger: Money,
     *         general_ledger: Money,
     *         difference: Money,
     *         reconciles: bool,
     *     }>,
     *     totals: array{
     *         subledger: Money,
     *         general_ledger: Money,
     *         difference: Money,
     *         reconciles: bool,
     *     },
     *     as_of: CarbonImmutable,
     * }
     */
    public function arControlReconciliation(Company $company): array
    {
        $currency = $company->base_currency_code;
        $asOf = CarbonImmutable::now()->startOfDay();
        $accounts = $this->receivableAccounts($company);

        $totals = [
            'subledger' => Money::zero($currency),
            'general_ledger' => Money::zero($currency),
            'difference' => Money::zero($currency),
            'reconciles' => true,
        ];

        if ($accounts === []) {
            return ['rows' => [], 'totals' => $totals, 'as_of' => $asOf];
        }

        $subledger = $this->subledgerByReceivableAccount($company);
        $rows = [];

        foreach ($accounts as $account) {
            $owed = $subledger[(string) $account->getKey()] ?? Money::zero($currency);

            // The ledger side is asked of `LedgerBalanceService` rather than computed here. It already signs
            // the balance by the account's normal balance and already counts reversed entries alongside posted
            // ones, which is what makes a cancelled invoice net to nothing.
            $ledger = $this->balances->balanceAsAt($company, $account, $asOf);

            // Ledger minus subledger, so a positive difference means the books carry more receivable than the
            // invoices account for — the direction a stray manual journal shows up in.
            $difference = $ledger->minus($owed);

            $rows[] = [
                'account' => $account,
                'subledger' => $owed,
                'general_ledger' => $ledger,
                'difference' => $difference,
                'reconciles' => $difference->isZero(),
            ];

            $totals['subledger'] = $totals['subledger']->plus($owed);
            $totals['general_ledger'] = $totals['general_ledger']->plus($ledger);
            $totals['difference'] = $totals['difference']->plus($difference);
        }

        // Every account has to agree, not just the total. Two opposite differences summing to zero is a
        // reconciliation failure that a grand total alone would report as success.
        $totals['reconciles'] = array_reduce(
            $rows,
            static fn (bool $carry, array $row): bool => $carry && $row['reconciles'],
            true,
        );

        return [
            'rows' => $this->sortedByAccountCode($rows),
            'totals' => $totals,
            'as_of' => $asOf,
        ];
    }

    /**
     * Every account this company's invoices have posted a receivable to, plus its system account.
     *
     * Read from the ledger rather than from `customers`, for the reason set out above: the current setting is
     * not evidence of where an old invoice posted. Reversal entries carry the same source document and mirror
     * the original's line order, so they name the same account and `DISTINCT` absorbs them.
     *
     * @return list<Account>
     */
    private function receivableAccounts(Company $company): array
    {
        /** @var list<string> $posted */
        $posted = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $company->getKey())
            ->where('e.source_type', SalesInvoice::MORPH_ALIAS)
            ->where('l.line_number', 1)
            ->distinct()
            ->pluck('l.account_id')
            ->all();

        $systemAccount = Account::query()
            ->forCompany((string) $company->getKey())
            ->withSystemKey(Account::TRADE_RECEIVABLES)
            ->first();

        if ($systemAccount !== null) {
            $posted[] = (string) $systemAccount->getKey();
        }

        if ($posted === []) {
            return [];
        }

        return array_values(
            Account::query()
                ->forCompany((string) $company->getKey())
                ->whereIn('id', array_values(array_unique($posted)))
                ->get()
                ->all()
        );
    }

    /**
     * What is currently owed, grouped by the account each invoice actually posted its receivable to.
     *
     * Two queries rather than one join, and the reason is `scopeForCompany()` and `scopeCollectable()`: both
     * emit unqualified column names, and `journal_entries` carries its own `company_id` and `status`, so
     * joining them into the same statement makes both references ambiguous. Qualifying would mean either
     * changing scopes other code depends on or restating the collectable status list here — and restating it
     * is exactly the drift this service avoids everywhere else.
     *
     * So the invoices come back through their own scopes, and a second query maps each posting to its
     * receivable account. No N+1: one query for the invoices, one for the whole set of entries.
     *
     * @return array<string, Money>
     */
    private function subledgerByReceivableAccount(Company $company): array
    {
        $currency = $company->base_currency_code;

        $invoices = SalesInvoice::query()
            ->forCompany((string) $company->getKey())
            ->collectable()
            ->get(['id', 'journal_entry_id', 'amount_due']);

        if ($invoices->isEmpty()) {
            return [];
        }

        /** @var array<string, string> $accountByEntry */
        $accountByEntry = DB::table('journal_lines')
            ->whereIn('journal_entry_id', $invoices->pluck('journal_entry_id')->filter()->all())
            ->where('line_number', 1)
            ->pluck('account_id', 'journal_entry_id')
            ->all();

        $byAccount = [];

        foreach ($invoices as $invoice) {
            $entryId = $invoice->journal_entry_id;
            $accountId = $entryId === null ? null : ($accountByEntry[$entryId] ?? null);

            // A collectable invoice with no posting cannot exist through any supported path — `issue()` always
            // writes one, and the status CHECK only permits a null entry on a draft. Left out rather than
            // guessed at: it would surface as a difference on the ledger side, which is where an operator
            // should see it.
            if ($accountId === null) {
                continue;
            }

            $owed = Money::of((string) $invoice->amount_due, $currency);

            $byAccount[$accountId] = isset($byAccount[$accountId])
                ? $byAccount[$accountId]->plus($owed)
                : $owed;
        }

        return $byAccount;
    }

    /**
     * Orders reconciliation rows by account code, which is how an accountant reads a chart.
     *
     * @param  list<array{account: Account, subledger: Money, general_ledger: Money, difference: Money, reconciles: bool}>  $rows
     * @return list<array{account: Account, subledger: Money, general_ledger: Money, difference: Money, reconciles: bool}>
     */
    private function sortedByAccountCode(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp(
            (string) $a['account']->code,
            (string) $b['account']->code,
        ));

        return $rows;
    }

    /**
     * Orders report rows by a `Money` column descending, then by customer code.
     *
     * Compared on `minorUnits`, an integer at the ledger's scale — exact, and it sidesteps how two decimal
     * strings order. The code tie-break is what stops two equal balances appearing in whatever order the
     * database happened to return them, which would make the same report differ between runs.
     *
     * Returns rather than sorting by reference, and templated on the row shape: a by-reference parameter typed
     * loosely enough to accept both reports would widen the caller's own type and lose the shape its return
     * annotation promises.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    private function sortedByAmountThenCode(array $rows, string $amountKey): array
    {
        usort($rows, static function (array $a, array $b) use ($amountKey): int {
            /** @var Money $left */
            $left = $a[$amountKey];
            /** @var Money $right */
            $right = $b[$amountKey];

            $byAmount = $right->minorUnits <=> $left->minorUnits;

            if ($byAmount !== 0) {
                return $byAmount;
            }

            /** @var Customer $customerA */
            $customerA = $a['customer'];
            /** @var Customer $customerB */
            $customerB = $b['customer'];

            return strcmp((string) $customerA->code, (string) $customerB->code);
        });

        return $rows;
    }
}
