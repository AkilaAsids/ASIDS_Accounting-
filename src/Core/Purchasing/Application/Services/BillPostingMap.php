<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

use Asids\Core\Accounting\Application\DTOs\ForeignAmount;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Purchasing\Domain\Exceptions\BillCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\BillLine;
use Asids\Core\Sales\Application\Services\LedgerNarration;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Turns a bill into the journal lines that represent it — the payable-side mirror of `InvoicePostingMap`, with
 * debits and credits swapped.
 *
 * A pure mapping. It reads a bill, resolves the accounts involved, and returns `JournalLineData` for the
 * existing `PostingService` to post. **It writes nothing, posts nothing, and reserves no document number** — so
 * it can be exercised, and got wrong, without touching the ledger. Stage 5 connects it to posting.
 *
 * THE SHAPE OF A BILL POSTING
 * ---------------------------
 * A debit to expense for what was bought; a debit to input VAT for what was charged and is recoverable; a
 * single credit to trade payables for the total owed. For a 100,000 purchase at 18% VAT:
 *
 *   Operating Expense     100,000.00
 *   Input VAT Recoverable  18,000.00
 *     Trade Payables                 118,000.00
 *
 * Debits first, the single payable credit last — a purchase entry reads debits-first, since here the *many*
 * are the debits (the deliberate, low-stakes divergence from sales' receivable-first order).
 *
 * GROUPING, AND WHY IT IS NOT OPTIONAL
 * ------------------------------------
 * Lines are aggregated by account, not copied one-for-one. A forty-line bill against one expense account
 * produces one debit, not forty. The detail it would add is already on the bill, which the journal entry cites
 * through its source document.
 *
 * THE ARITHMETIC IS ADDITION ONLY
 * -------------------------------
 * Nothing here computes tax or applies a rate. The line already carries `tax_amount`, rounded to the currency
 * when the draft was written, and `line_subtotal` net of every discount. This sums stored values.
 *
 * That is what makes the entry balance exactly. `bills_total_check` asserts `total = subtotal + tax_total`, and
 * the service maintains `subtotal = Σ line_subtotal` and `tax_total = Σ tax_amount`. So the credit equals the
 * sum of the debits by construction — no rounding happens here.
 */
final readonly class BillPostingMap
{
    /**
     * The journal lines representing this bill.
     *
     * Ordered expense debits (by account code), then input VAT debits (by input account code), then the single
     * payable credit — so an entry reads the way an accountant would write it, and two runs over the same bill
     * produce identical output.
     *
     * @return list<JournalLineData>
     */
    public function for(Bill $bill): array
    {
        // Everything the map reads, loaded up front. Reaching for `$line->taxCode` inside the loop would be an
        // N+1 in production and a `LazyLoadingViolationException` under `Model::preventLazyLoading()`.
        $bill->loadMissing(['company', 'supplier', 'lines.taxCode']);

        $lines = $bill->lines;

        if ($lines->isEmpty()) {
            throw BillCannotBePosted::withoutLines();
        }

        $currency = $bill->currency_code;

        // Built in the bill's transaction currency (the calculator already computed totals in it): the debit
        // lines and the single payable credit.
        $debits = [
            ...$this->expenseLines($bill, $lines, $currency),
            ...$this->inputTaxLines($bill, $lines, $currency),
        ];
        $payable = $this->payableLines($bill, $currency);

        $base = $bill->company->base_currency_code;

        // Base-currency bill: byte-for-byte today — no conversion, no overlay, no FX line (a bill is a
        // booking, not a settlement; ADR 0022 §C2).
        if ($currency === $base || $bill->exchange_rate === null) {
            return [...$debits, ...$payable];
        }

        // Foreign bill: convert each line to base at the booking rate, with the transaction face as the
        // overlay, and reconcile the debit residue so the debits sum EXACTLY to the payable face
        // (`round(total × rate)`) — the entry balances in base with no FX line (fork 3).
        return $this->toBaseCurrency($debits, $payable, $base, $bill->exchange_rate, $bill->company->currency_precision);
    }

    /**
     * The account this bill's credit lands in.
     *
     * Public because a caller asking "where will this post?" should not have to build the whole entry to find
     * out. Always the company's `trade_payables` system account — no per-supplier override this wave (Gate-1
     * dec. 3). Never resolved by code: a company may renumber its chart freely, which is why the key exists.
     */
    public function payableAccountFor(Bill $bill): Account
    {
        $bill->loadMissing('company');

        $account = Account::query()
            ->forCompany($bill->company_id)
            ->withSystemKey(Account::TRADE_PAYABLES)
            ->first();

        if ($account === null) {
            throw BillCannotBePosted::withoutPayableAccount($bill->company->name);
        }

        if ($account->type !== AccountType::Liability) {
            throw BillCannotBePosted::payableAccountWrongType($account);
        }

        return $this->assertPostable($account, 'payable');
    }

    /**
     * Convert a bill's transaction-currency lines to base, reconciling the debit residue onto the largest
     * debit so `Σ debit base = payable face` exactly (ADR 0022 §C2, fork 3).
     *
     * @param  list<JournalLineData>  $debits
     * @param  list<JournalLineData>  $payable  0 or 1 credit line
     * @return list<JournalLineData>
     */
    private function toBaseCurrency(array $debits, array $payable, string $base, string $rate, int $precision): array
    {
        $convertedPayable = array_map(
            fn (JournalLineData $line): JournalLineData => $this->convertLine($line, $base, $rate, $precision),
            $payable,
        );

        $convertedDebits = array_map(
            fn (JournalLineData $line): JournalLineData => $this->convertLine($line, $base, $rate, $precision),
            $debits,
        );

        // The face the debits must sum to: the payable credit's base amount (`round(total × rate)`).
        $payableBase = $convertedPayable === []
            ? Money::zero($base)
            : ($convertedPayable[0]->credit ?? Money::zero($base));

        // Net base of the debit set (a mid-bill correction may sit on the credit side): debits add, credits
        // subtract. The residue that reconciles it to the face is placed on the largest-magnitude debit.
        $net = Money::zero($base);
        $largest = null;
        $largestUnits = -1;

        foreach ($convertedDebits as $index => $line) {
            $net = $line->debit !== null ? $net->plus($line->debit) : $net->minus($line->credit ?? Money::zero($base));

            $units = ($line->debit ?? $line->credit ?? Money::zero($base))->absolute()->minorUnits;
            if ($line->debit !== null && $units > $largestUnits) {
                $largestUnits = $units;
                $largest = $index;
            }
        }

        $residue = $payableBase->minus($net);

        if ($largest !== null && ! $residue->isZero()) {
            $adjusted = $convertedDebits[$largest];
            $convertedDebits[$largest] = new JournalLineData(
                accountId: $adjusted->accountId,
                debit: ($adjusted->debit ?? Money::zero($base))->plus($residue),
                credit: null,
                branchId: $adjusted->branchId,
                description: $adjusted->description,
                foreign: $adjusted->foreign,
            );
        }

        return [...$convertedDebits, ...$convertedPayable];
    }

    /**
     * One transaction-currency line re-expressed in base: the base amount is `round(txn × rate)` and the
     * transaction face rides as the overlay (`ForeignAmount`). The side is preserved.
     */
    private function convertLine(JournalLineData $line, string $base, string $rate, int $precision): JournalLineData
    {
        $txn = $line->debit ?? $line->credit ?? Money::zero($base);
        $converted = $txn->convertedTo($base, $rate, $precision);

        return new JournalLineData(
            accountId: $line->accountId,
            debit: $line->debit !== null ? $converted : null,
            credit: $line->credit !== null ? $converted : null,
            branchId: $line->branchId,
            description: $line->description,
            foreign: new ForeignAmount($txn, $rate),
        );
    }

    /**
     * @return list<JournalLineData>
     */
    private function payableLines(Bill $bill, string $currency): array
    {
        $total = Money::of($bill->total, $currency);

        // A zero bill produces no line. `journal_lines_one_sided_check` requires exactly one side positive, so
        // a zero line could not be stored — and Stage 5 refuses to post a zero bill anyway.
        if ($total->isZero()) {
            return [];
        }

        $account = $this->payableAccountFor($bill);

        return [$this->line($account, $total, $bill->branch_id, LedgerNarration::limit(sprintf(
            'Bill from %s',
            $bill->supplier->name,
        )), creditSide: true)];
    }

    /**
     * Expense debits, one per distinct account named by the lines.
     *
     * @param  EloquentCollection<int, BillLine>  $lines
     * @return list<JournalLineData>
     */
    private function expenseLines(Bill $bill, EloquentCollection $lines, string $currency): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $accountId = $line->expense_account_id;

            $totals[$accountId] = isset($totals[$accountId])
                ? $totals[$accountId]->plus(Money::of($line->line_subtotal, $currency))
                : Money::of($line->line_subtotal, $currency);
        }

        $journalLines = [];

        foreach ($this->orderedByCode($bill, array_keys($totals), 'expense') as $account) {
            $amount = $totals[$account->getKey()];

            if ($amount->isZero()) {
                // Two lines that cancel — a charge and its correction against one account. Nothing to post.
                continue;
            }

            if ($account->type !== AccountType::Expense) {
                throw BillCannotBePosted::expenseAccountWrongType($account);
            }

            // Negative flips the side rather than storing a negative debit, which the non-negative CHECK on
            // `journal_lines` would refuse. A net credit against an expense is a credit to that expense.
            $journalLines[] = $this->line(
                $account,
                $amount->absolute(),
                $bill->branch_id,
                LedgerNarration::limit(sprintf('%s — %s', $account->name, $bill->supplier->name)),
                creditSide: $amount->isNegative(),
            );
        }

        return $journalLines;
    }

    /**
     * Input VAT debits, one per distinct input account.
     *
     * Grouped by the *account*, not by the tax code: two codes sharing one input account produce one line. The
     * amounts come from the lines' stored `tax_amount`, so mixed rates need no special handling.
     *
     * @param  EloquentCollection<int, BillLine>  $lines
     * @return list<JournalLineData>
     */
    private function inputTaxLines(Bill $bill, EloquentCollection $lines, string $currency): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $tax = Money::of($line->tax_amount, $currency);

            if ($tax->isZero()) {
                // Zero-rated and exempt lines reach here and contribute nothing: reportable on a return but
                // recovering no input tax.
                continue;
            }

            if ($line->tax_code_id === null) {
                throw BillCannotBePosted::taxWithoutCode($line->line_number);
            }

            $taxCode = $line->taxCode;
            $inputAccountId = $taxCode?->input_account_id;

            if ($taxCode === null || $inputAccountId === null) {
                throw BillCannotBePosted::taxCodeHasNoInputAccount($taxCode->code ?? $line->tax_code_id);
            }

            $totals[$inputAccountId] = isset($totals[$inputAccountId])
                ? $totals[$inputAccountId]->plus($tax)
                : $tax;
        }

        $journalLines = [];

        foreach ($this->orderedByCode($bill, array_keys($totals), 'input tax') as $account) {
            $amount = $totals[$account->getKey()];

            if ($amount->isZero()) {
                continue;
            }

            // Input VAT is recoverable — an asset. Re-checked here even though `TaxCodeService` refuses to
            // configure a non-asset: an account with no postings can still be reclassified.
            if ($account->type !== AccountType::Asset) {
                throw BillCannotBePosted::taxAccountWrongType($account);
            }

            $journalLines[] = $this->line(
                $account,
                $amount->absolute(),
                $bill->branch_id,
                LedgerNarration::limit(sprintf('%s — %s', $account->name, $bill->supplier->name)),
                creditSide: $amount->isNegative(),
            );
        }

        return $journalLines;
    }

    /**
     * The named accounts, validated and sorted by code.
     *
     * @param  list<string>  $accountIds
     * @return list<Account>
     */
    private function orderedByCode(Bill $bill, array $accountIds, string $role): array
    {
        /** @var array<string, Account> $found */
        $found = Account::query()
            ->forCompany($bill->company_id)
            ->whereKey($accountIds)
            ->orderBy('code')
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($accountIds as $id) {
            if (! isset($found[$id])) {
                // Either it belongs to another company or it has been hard-deleted. Both are the same refusal
                // from the bill's point of view: it cannot post there.
                throw BillCannotBePosted::accountOutsideCompany($role, $id);
            }
        }

        $accounts = [];

        foreach ($found as $account) {
            $accounts[] = $this->assertPostable($account, $role);
        }

        return $accounts;
    }

    private function assertPostable(Account $account, string $role): Account
    {
        if (! $account->acceptsPostings()) {
            throw BillCannotBePosted::accountNotPostable($role, $account);
        }

        return $account;
    }

    private function line(
        Account $account,
        Money $amount,
        ?string $branchId,
        string $description,
        bool $creditSide = false,
    ): JournalLineData {
        return new JournalLineData(
            accountId: (string) $account->getKey(),
            debit: $creditSide ? null : $amount,
            credit: $creditSide ? $amount : null,
            branchId: $branchId,
            description: $description,
        );
    }
}
