<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBePosted;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Turns an invoice into the journal lines that represent it.
 *
 * A pure mapping. It reads an invoice, resolves the accounts involved, and returns `JournalLineData` for the
 * existing `PostingService` to post. **It writes nothing, posts nothing, and reserves no document number** — so it
 * can be exercised, and got wrong, without touching the ledger. Stage 3 connects it to issuing.
 *
 * THE SHAPE OF A SALES POSTING
 * ----------------------------
 * One debit to receivables for the invoice total; credits to revenue for what was sold; credits to output tax for
 * what was charged on the authority's behalf. For a 100,000 sale at 18% with a 5,000 discount:
 *
 *   Trade Receivables    112,100.00
 *     Sales Revenue                  95,000.00
 *     Output VAT Payable             17,100.00
 *
 * GROUPING, AND WHY IT IS NOT OPTIONAL
 * ------------------------------------
 * Lines are aggregated by account, not copied one-for-one. A forty-line invoice against one revenue account
 * produces one credit, not forty. Without that a month of invoicing makes the account ledger unreadable, and the
 * detail it would add is already on the invoice — which the journal entry cites through its source document.
 *
 * THE ARITHMETIC IS ADDITION ONLY
 * -------------------------------
 * Nothing here computes tax or applies a rate. The line already carries `tax_amount`, rounded to the currency when
 * the draft was written, and `line_subtotal` net of every discount. This sums stored values with `Money::plus`.
 *
 * That is what makes the entry balance exactly rather than nearly. `sales_invoices_total_check` asserts
 * `total = subtotal + tax_total`, and the service maintains `subtotal = Σ line_subtotal` and
 * `tax_total = Σ tax_amount`. So the debit equals the sum of the credits by construction, and the rounding that
 * would otherwise bite — rounding a total rather than summing rounded parts — cannot happen here because no
 * rounding happens here.
 *
 * WHICH ACCOUNTS ARE RESOLVED WHEN
 * --------------------------------
 * The revenue account comes from the line, where the draft recorded it. The receivable and tax output accounts are
 * resolved from current configuration at posting time, per the approved Milestone 5 design: the posted journal
 * entry is the permanent snapshot, and it names the accounts it used. Nothing here mutates the invoice or its
 * stored tax figures.
 */
final readonly class InvoicePostingMap
{
    /**
     * The journal lines representing this invoice.
     *
     * Ordered receivable, then revenue, then tax, each group sorted by account code — so an entry reads the way an
     * accountant would write it, and two runs over the same invoice produce identical output.
     *
     * @return list<JournalLineData>
     */
    public function for(SalesInvoice $invoice): array
    {
        // Everything the map reads, loaded up front. Reaching for `$line->taxCode` inside the loop would be an
        // N+1 in production and a `LazyLoadingViolationException` under `Model::preventLazyLoading()` — which is
        // how this was caught rather than shipped.
        $invoice->loadMissing(['company', 'customer', 'lines.taxCode']);

        $lines = $invoice->lines;

        if ($lines->isEmpty()) {
            throw InvoiceCannotBePosted::withoutLines();
        }

        $currency = $invoice->currency_code;

        return [
            ...$this->receivableLines($invoice, $currency),
            ...$this->revenueLines($invoice, $lines, $currency),
            ...$this->taxLines($invoice, $lines, $currency),
        ];
    }

    /**
     * The account this invoice's debit lands in.
     *
     * Public because Stage 3 validates it before issuing, and because a caller asking "where will this post?"
     * should not have to build the whole entry to find out.
     *
     * The customer's own account wins when it has one; otherwise the company's `trade_receivables` system account.
     * Never resolved by code — a company may renumber its chart freely, which is why the system key exists.
     */
    public function receivableAccountFor(SalesInvoice $invoice): Account
    {
        $invoice->loadMissing(['company', 'customer']);

        $explicit = $invoice->customer->receivable_account_id;

        if ($explicit !== null) {
            $account = $this->accountWithinCompany($invoice, $explicit, 'receivable');
        } else {
            $account = Account::query()
                ->forCompany($invoice->company_id)
                ->withSystemKey(Account::TRADE_RECEIVABLES)
                ->first();

            if ($account === null) {
                throw InvoiceCannotBePosted::withoutReceivableAccount($invoice->company->name);
            }
        }

        if ($account->type !== AccountType::Asset) {
            throw InvoiceCannotBePosted::receivableAccountWrongType($account);
        }

        return $this->assertPostable($account, 'receivable');
    }

    /**
     * @return list<JournalLineData>
     */
    private function receivableLines(SalesInvoice $invoice, string $currency): array
    {
        $total = Money::of($invoice->total, $currency);

        // A zero invoice produces no lines at all. `journal_lines_one_sided_check` requires exactly one side to be
        // positive, so a zero line could not be stored — and Stage 3 refuses to issue a zero invoice anyway.
        if ($total->isZero()) {
            return [];
        }

        $account = $this->receivableAccountFor($invoice);

        // Clipped to the ledger's column. `customers.name` is as wide as `journal_lines.description`, so this
        // narration could exceed it on its own — see `LedgerNarration`. Nothing but the text changes: the
        // account, the amount, the side and this line's position are all untouched.
        return [$this->line($account, $total, $invoice->branch_id, LedgerNarration::limit(sprintf(
            'Invoice to %s',
            $invoice->customer->name,
        )))];
    }

    /**
     * Revenue credits, one per distinct account named by the lines.
     *
     * @param  EloquentCollection<int, SalesInvoiceLine>  $lines
     * @return list<JournalLineData>
     */
    private function revenueLines(SalesInvoice $invoice, EloquentCollection $lines, string $currency): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $accountId = $line->revenue_account_id;

            $totals[$accountId] = isset($totals[$accountId])
                ? $totals[$accountId]->plus(Money::of($line->line_subtotal, $currency))
                : Money::of($line->line_subtotal, $currency);
        }

        $journalLines = [];

        foreach ($this->orderedByCode($invoice, array_keys($totals), 'revenue') as $account) {
            $amount = $totals[$account->getKey()];

            if ($amount->isZero()) {
                // Two lines that cancel — a charge and its correction against one account. Nothing to post, and a
                // zero-sided line could not be stored anyway.
                continue;
            }

            if ($account->type !== AccountType::Income) {
                throw InvoiceCannotBePosted::revenueAccountWrongType($account);
            }

            // Negative flips the side rather than storing a negative credit, which the non-negative CHECK on
            // `journal_lines` would refuse. A net credit line against revenue is a debit to revenue.
            $journalLines[] = $this->line(
                $account,
                $amount->absolute(),
                $invoice->branch_id,
                // Both halves are user-controlled and each is as wide as the column, so no per-part budget
                // could work here — see `LedgerNarration`. Text only; the side, amount and account are unchanged.
                LedgerNarration::limit(sprintf('%s — %s', $account->name, $invoice->customer->name)),
                creditSide: $amount->isPositive(),
            );
        }

        return $journalLines;
    }

    /**
     * Output tax credits, one per distinct tax output account.
     *
     * Grouped by the *account*, not by the tax code: two codes sharing one output account produce one line, which
     * is what a balance sheet wants. The amounts come from the lines' stored `tax_amount`, so mixed rates need no
     * special handling — each line already carries what it charged.
     *
     * @param  EloquentCollection<int, SalesInvoiceLine>  $lines
     * @return list<JournalLineData>
     */
    private function taxLines(SalesInvoice $invoice, EloquentCollection $lines, string $currency): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $tax = Money::of($line->tax_amount, $currency);

            if ($tax->isZero()) {
                // Zero-rated and exempt lines reach here and contribute nothing, which is the correct treatment:
                // they are reportable on a return but post no liability.
                continue;
            }

            if ($line->tax_code_id === null) {
                throw InvoiceCannotBePosted::taxWithoutCode($line->line_number);
            }

            $taxCode = $line->taxCode;
            $outputAccountId = $taxCode?->output_account_id;

            if ($taxCode === null || $outputAccountId === null) {
                throw InvoiceCannotBePosted::taxCodeHasNoOutputAccount($taxCode->code ?? $line->tax_code_id);
            }

            $totals[$outputAccountId] = isset($totals[$outputAccountId])
                ? $totals[$outputAccountId]->plus($tax)
                : $tax;
        }

        $journalLines = [];

        foreach ($this->orderedByCode($invoice, array_keys($totals), 'output tax') as $account) {
            $amount = $totals[$account->getKey()];

            if ($amount->isZero()) {
                continue;
            }

            // Re-checked here even though `TaxCodeService` refuses to configure a non-liability: an account with
            // no postings can still be reclassified, and a brand new output account has none. Output tax credited
            // to income leaves the books tying and the return understated.
            if ($account->type !== AccountType::Liability) {
                throw InvoiceCannotBePosted::taxAccountWrongType($account);
            }

            $journalLines[] = $this->line(
                $account,
                $amount->absolute(),
                $invoice->branch_id,
                // Both halves are user-controlled and each is as wide as the column, so no per-part budget
                // could work here — see `LedgerNarration`. Text only; the side, amount and account are unchanged.
                LedgerNarration::limit(sprintf('%s — %s', $account->name, $invoice->customer->name)),
                creditSide: $amount->isPositive(),
            );
        }

        return $journalLines;
    }

    /**
     * The named accounts, validated and sorted by code.
     *
     * One query rather than one per account, and the sort makes the entry's line order deterministic — which
     * matters for reading a printed entry and for tests that assert on position.
     *
     * @param  list<string>  $accountIds
     * @return list<Account>
     */
    private function orderedByCode(SalesInvoice $invoice, array $accountIds, string $role): array
    {
        /** @var array<string, Account> $found */
        $found = Account::query()
            ->forCompany($invoice->company_id)
            ->whereKey($accountIds)
            ->orderBy('code')
            ->get()
            ->keyBy('id')
            ->all();

        $accounts = [];

        foreach ($accountIds as $id) {
            if (! isset($found[$id])) {
                // Either it belongs to another company or it has been hard-deleted. Both are the same refusal from
                // the invoice's point of view: it cannot post there.
                throw InvoiceCannotBePosted::accountOutsideCompany($role, $id);
            }
        }

        foreach ($found as $account) {
            $accounts[] = $this->assertPostable($account, $role);
        }

        return $accounts;
    }

    private function accountWithinCompany(SalesInvoice $invoice, string $accountId, string $role): Account
    {
        $account = Account::query()
            ->forCompany($invoice->company_id)
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw InvoiceCannotBePosted::accountOutsideCompany($role, $accountId);
        }

        return $account;
    }

    private function assertPostable(Account $account, string $role): Account
    {
        if (! $account->acceptsPostings()) {
            throw InvoiceCannotBePosted::accountNotPostable($role, $account);
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
