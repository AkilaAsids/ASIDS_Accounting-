<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;

/**
 * Turns a receipt into the journal lines that represent it.
 *
 * A pure mapping, to the same contract as `InvoicePostingMap`: it reads a receipt, resolves the accounts
 * involved, and returns `JournalLineData` for the existing `PostingService` to post. **It writes nothing,
 * posts nothing, and reserves no document number** — so it can be exercised, and got wrong, without touching
 * the ledger. That is why the AC-3.2 test can feed it a hand-built receipt directly.
 *
 * THE SHAPE OF A RECEIPT POSTING (variable-line, ADR 0016 §C)
 * -----------------------------------------------------------
 * Two lines when the receipt is fully allocated, three when it leaves a remainder:
 *
 *   Dr  Bank / Cash (the named asset account)    <receipt amount>
 *       Cr  Trade Receivables                            <Σ allocations>
 *       Cr  Customer Advances                            <remainder>          (only when remainder > 0)
 *
 * The entry balances by construction: `amount = Σ allocations + remainder`, all summed with `Money::plus` — the
 * same "sum stored values, never recompute" discipline that makes `InvoicePostingMap` balance. When the
 * remainder is zero the Customer Advances line is omitted entirely and the result is byte-for-byte the two-line
 * entry receipts posted before ADR 0016 (AC-CR-6.1 regression safety). No rounding happens here because no
 * arithmetic beyond addition and subtraction happens here.
 *
 * WHICH ACCOUNTS, AND THE AC-3.2 REFUSAL
 * --------------------------------------
 * The debit is the account the receipt names, validated in-company, postable and asset. The credit is
 * resolved the *same way invoices resolve it* — reusing `InvoicePostingMap::receivableAccountFor()` per
 * allocated invoice, which honours the customer's own override before the company's `TRADE_RECEIVABLES` system
 * account, so the subledger and the control account keep agreeing. A receipt is single-customer, so in
 * practice every allocated invoice resolves to one account; if more than one distinct account appears — an
 * invoice posted while the customer had a different override than now — the receipt is **refused** rather than
 * splitting the credit or picking one, because crediting today's account would leave the old one uncleared
 * (AC-3.2).
 */
final readonly class ReceiptPostingMap
{
    public function __construct(
        private InvoicePostingMap $invoiceMap,
    ) {}

    /**
     * The journal lines representing this receipt: the bank debit, the receivable credit for the allocated
     * portion, and — only when the receipt leaves a remainder — the Customer Advances credit for it.
     *
     * @return list<JournalLineData>
     */
    public function for(CustomerReceipt $receipt): array
    {
        $receipt->loadMissing(['company', 'customer', 'bankAccount', 'allocations.invoice']);

        if ($receipt->allocations->isEmpty()) {
            throw ReceiptCannotBePosted::withoutAllocations();
        }

        $currency = $receipt->currency_code;
        $amount = Money::of($receipt->amount, $currency);

        // Σ allocations, summed from the stored allocation amounts — never recomputed. The remainder is what is
        // left of the receipt after the invoices it names are cleared, held as customer advances.
        $allocated = array_reduce(
            $receipt->allocations->all(),
            static fn (Money $carry, $allocation): Money => $carry->plus(Money::of($allocation->amount, $currency)),
            Money::zero($currency),
        );

        $remainder = $amount->minus($allocated);

        $bank = $this->bankAccountFor($receipt);
        $receivable = $this->receivableAccountFor($receipt);

        $narration = LedgerNarration::limit(sprintf('Receipt %s from %s', $receipt->number, $receipt->customer->name));

        $lines = [
            $this->line($bank, $amount, $receipt->branch_id, $narration),
            $this->line($receivable, $allocated, $receipt->branch_id, $narration, creditSide: true),
        ];

        // Emitted only when positive: a fully-allocated receipt yields the identical two-line entry it did
        // before ADR 0016. The remainder is non-negative by construction — the service refuses Σ > amount.
        if ($remainder->isPositive()) {
            $lines[] = $this->line(
                $this->customerAdvancesAccountFor($receipt),
                $remainder,
                $receipt->branch_id,
                $narration,
                creditSide: true,
            );
        }

        return $lines;
    }

    /**
     * The asset account the receipt debits.
     *
     * Public because the service validates it before recording, and because a caller asking "where will this
     * land?" should not have to build the whole entry to find out. Validated in-company, postable and asset —
     * the checks the database cannot make, since a CHECK cannot join to `accounts`.
     */
    public function bankAccountFor(CustomerReceipt $receipt): Account
    {
        $account = Account::query()
            ->forCompany($receipt->company_id)
            ->whereKey($receipt->bank_account_id)
            ->first();

        if ($account === null) {
            throw ReceiptCannotBePosted::accountOutsideCompany('bank', (string) $receipt->bank_account_id);
        }

        if ($account->type !== AccountType::Asset) {
            throw ReceiptCannotBePosted::bankAccountWrongType($account);
        }

        if (! $account->acceptsPostings()) {
            throw ReceiptCannotBePosted::accountNotPostable('bank', $account);
        }

        return $account;
    }

    /**
     * The single receivable account this receipt credits, or a refusal if its invoices disagree.
     *
     * Resolved per allocated invoice, exactly as the invoice that raised the receivable debited it. Reuses
     * `InvoicePostingMap::receivableAccountFor()`, so the resolution — customer override, else the system
     * account, with its own type and postability checks — lives in one place for both sides of the ledger.
     */
    public function receivableAccountFor(CustomerReceipt $receipt): Account
    {
        $receipt->loadMissing('allocations.invoice');

        /** @var array<string, Account> $distinct */
        $distinct = [];

        foreach ($receipt->allocations as $allocation) {
            $account = $this->invoiceMap->receivableAccountFor($allocation->invoice);
            $distinct[(string) $account->getKey()] = $account;
        }

        if (count($distinct) > 1) {
            throw ReceiptCannotBePosted::receivableAccountsDiffer(count($distinct));
        }

        // Guaranteed non-empty: `for()` refuses a receipt with no allocations before reaching here.
        return array_values($distinct)[0];
    }

    /**
     * The Customer Advances account the remainder credits (ADR 0016 §A).
     *
     * Resolved by system key, never by code — a company may renumber — and validated a postable liability, the
     * checks a CHECK cannot make because it cannot join to `accounts`. A separate resolution from the
     * receivable, never conflated with it: the remainder is a liability to the customer, not a reduction of the
     * receivable. Refused with `withoutCustomerAdvancesAccount()` when a company has none, the mirror of the
     * invoice map's missing-receivable refusal.
     */
    public function customerAdvancesAccountFor(CustomerReceipt $receipt): Account
    {
        $account = Account::query()
            ->forCompany($receipt->company_id)
            ->withSystemKey(Account::CUSTOMER_ADVANCES)
            ->first();

        if ($account === null) {
            throw ReceiptCannotBePosted::withoutCustomerAdvancesAccount();
        }

        if ($account->type !== AccountType::Liability) {
            throw ReceiptCannotBePosted::accountNotPostable('customer advances', $account);
        }

        if (! $account->acceptsPostings()) {
            throw ReceiptCannotBePosted::accountNotPostable('customer advances', $account);
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
