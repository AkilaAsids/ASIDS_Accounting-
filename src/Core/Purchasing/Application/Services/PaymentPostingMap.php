<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

use Asids\Core\Accounting\Application\DTOs\ForeignAmount;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Purchasing\Application\DTOs\PayableRelief;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Sales\Application\Services\LedgerNarration;

/**
 * Turns a payment into the journal lines that represent it — the exact inverse of `BillPostingMap`, with the
 * money flow reversed (a bill *creates* a payable, a payment *settles* it).
 *
 * A pure mapping. It reads, resolves the two accounts involved, and returns `JournalLineData` for the existing
 * `PostingService` to post. **It writes nothing, posts nothing, and reserves no document number** (mirror
 * `BillPostingMap.php:17-23`), so its refusals are free and run before the record-and-post transaction opens.
 *
 * THE SHAPE OF A PAYMENT POSTING
 * ------------------------------
 * Two lines, both equal to the payment amount. For a 100,000 settlement:
 *
 *   Trade Payables (2110)   100,000.00
 *       Bank Accounts (1120)            100,000.00
 *
 * Trade Payables (the debit) first, Bank/Cash (the credit) last — the mirror of a purchase reading
 * debits-first, and the order the ADR's worked example renders (§B).
 *
 * WHY IT TAKES PRIMITIVES, NOT A PERSISTED PAYMENT
 * ------------------------------------------------
 * `for()` runs *before* the payment exists (§C4 step 9): a payment is recorded and posted in one step, so
 * there is no draft row to load the way `BillPostingMap::for(Bill $bill)` loads one. It takes the company, the
 * caller-named bank account, the total, and the supplier (for the narration) directly.
 *
 * WHY THE ENTRY BALANCES WITH NO ROUNDING
 * ---------------------------------------
 * Full allocation is required (dec. 2), so `amount = Σ allocations`, so the single Dr Trade Payables line and
 * the single Cr Bank/Cash line are the same figure by construction — no arithmetic happens here. Trade
 * Payables is a control account: the per-bill detail lives in `payment_allocations` (the subledger) and the
 * journal cites the payment through its source document, exactly as bill-line detail is grouped into one
 * payable credit (`BillPostingMap.php:37-42`).
 */
final readonly class PaymentPostingMap
{
    /**
     * The journal lines representing this payment: `Dr Trade Payables (total) = Cr Bank/Cash (total)`.
     *
     * `$branchId` is the payment's reporting dimension, stamped on both lines exactly as `BillPostingMap`
     * stamps a bill's `branch_id` on every line (`BillPostingMap.php:126,169,234`; ADR §A2) — so branch-level
     * ledger reports (branch P&L, branch trial balance) include supplier payments.
     *
     * @return list<JournalLineData>
     */
    public function for(Company $company, Account $bankAccount, Money $total, Money $wht, Supplier $supplier, ?string $branchId = null): array
    {
        $payable = $this->payableAccountFor($company);
        $bank = $this->assertBankAccount($company, $bankAccount);
        $net = $total->minus($wht);                                    // wht ≤ total guaranteed by the service + DB CHECK

        $narration = LedgerNarration::limit(sprintf('Payment to %s', $supplier->name));

        // Dr Trade Payables (gross) — always.
        $lines = [$this->line($payable, $total, $narration, $branchId, creditSide: false)];

        // Cr Bank/Cash (net). Omitted only in the 100%-withheld net-zero edge — a zero line would be refused
        // by journal_lines_one_sided_check anyway.
        if ($net->isPositive()) {
            $lines[] = $this->line($bank, $net, $narration, $branchId, creditSide: true);
        }

        // Cr WHT Payable (withheld) — the third line, only when withholding. Resolved here, not at the top, so
        // a zero-WHT payment never touches WHT Payable and a company that never withholds posts the 8a
        // two-line entry (AC-1.2).
        if ($wht->isPositive()) {
            $lines[] = $this->line($this->whtPayableAccountFor($company), $wht, $narration, $branchId, creditSide: true);
        }

        return $lines;
    }

    /**
     * The journal lines for a FOREIGN payment, with realised FX (ADR 0022 §D2). Base currency throughout; the
     * transaction face rides as the overlay on each non-FX line.
     *
     *   Dr Trade Payables   (one line per settled bill: base relieved at THAT bill's booking rate)
     *   Dr FX Loss / Cr FX Gain   (ONE plug line, base only, no overlay — makes the base entry balance)
     *       Cr Bank/Cash    (base = round(netCashTxn × paymentRate))
     *      [Cr WHT Payable   (base = round(whtTxn × paymentRate)) — only when withholding]
     *
     * The plug is `Σ base credits − Σ base debits`: `> 0` ⇒ Dr FX Loss (more base paid than relieved), `< 0` ⇒
     * Cr FX Gain, `= 0` ⇒ no FX line (rates matched). It guarantees the base entry balances to the cent for
     * any rates; the FX account is resolved only when the plug is non-zero (a company whose rates always match
     * never needs it).
     *
     * @param  list<PayableRelief>  $reliefs  per settled bill, base relieved + its transaction overlay
     * @return list<JournalLineData>
     */
    public function forForeign(
        Company $company,
        Account $bankAccount,
        array $reliefs,
        Money $netCashTxn,
        Money $whtTxn,
        string $paymentRate,
        int $precision,
        Supplier $supplier,
        ?string $branchId = null,
    ): array {
        $base = $company->base_currency_code;
        $payable = $this->payableAccountFor($company);
        $bank = $this->assertBankAccount($company, $bankAccount);
        $narration = LedgerNarration::limit(sprintf('Payment to %s', $supplier->name));

        $lines = [];
        $debitBase = Money::zero($base);

        // Dr Trade Payables — one line per settled bill, relieved at the bill's booking rate.
        foreach ($reliefs as $relief) {
            $lines[] = new JournalLineData(
                accountId: (string) $payable->getKey(),
                debit: $relief->baseRelieved,
                credit: null,
                branchId: $branchId,
                description: $narration,
                foreign: $relief->overlay,
            );
            $debitBase = $debitBase->plus($relief->baseRelieved);
        }

        $creditBase = Money::zero($base);

        // Cr Bank/Cash — the net cash, converted at the payment rate. Omitted in the 100%-withheld net-zero case.
        $netBase = $netCashTxn->convertedTo($base, $paymentRate, $precision);
        if ($netBase->isPositive()) {
            $lines[] = new JournalLineData(
                accountId: (string) $bank->getKey(),
                debit: null,
                credit: $netBase,
                branchId: $branchId,
                description: $narration,
                foreign: new ForeignAmount($netCashTxn, $paymentRate),
            );
            $creditBase = $creditBase->plus($netBase);
        }

        // Cr WHT Payable — the withheld tax, converted at the payment rate, only when withholding.
        if ($whtTxn->isPositive()) {
            $whtBase = $whtTxn->convertedTo($base, $paymentRate, $precision);
            $lines[] = new JournalLineData(
                accountId: (string) $this->whtPayableAccountFor($company)->getKey(),
                debit: null,
                credit: $whtBase,
                branchId: $branchId,
                description: $narration,
                foreign: new ForeignAmount($whtTxn, $paymentRate),
            );
            $creditBase = $creditBase->plus($whtBase);
        }

        // The FX plug — one base-only line, resolved only when non-zero.
        $plug = $creditBase->minus($debitBase);
        if (! $plug->isZero()) {
            $fx = $this->fxGainLossAccountFor($company);
            $lines[] = $plug->isPositive()
                ? new JournalLineData(accountId: (string) $fx->getKey(), debit: $plug, branchId: $branchId, description: $narration)
                : new JournalLineData(accountId: (string) $fx->getKey(), credit: $plug->absolute(), branchId: $branchId, description: $narration);
        }

        return $lines;
    }

    /**
     * The account a foreign settlement's realised gain/loss lands in — the company's `fx_gain_loss` system
     * account (ADR 0022 §A5), resolved by key. Mirrors `payableAccountFor()`'s checks. Called only when the FX
     * plug is non-zero, so a company whose rates always match never needs it.
     */
    public function fxGainLossAccountFor(Company $company): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->withSystemKey(Account::FX_GAIN_LOSS)
            ->first();

        if ($account === null) {
            throw PaymentCannotBePosted::withoutFxAccount($company->name);
        }

        if ($account->type !== AccountType::Income) {
            throw PaymentCannotBePosted::fxAccountWrongType($account);
        }

        return $this->assertPostable($account, 'FX gain/loss');
    }

    /**
     * The account this payment debits.
     *
     * Public because a caller asking "where will this post?" should not have to build the whole entry to find
     * out. Always the company's `trade_payables` system account — no per-supplier override this wave (Gate-1
     * dec. 6). Never resolved by code: a company may renumber its chart freely, which is why the key exists
     * (mirror `BillPostingMap::payableAccountFor`).
     */
    public function payableAccountFor(Company $company): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->withSystemKey(Account::TRADE_PAYABLES)
            ->first();

        if ($account === null) {
            throw PaymentCannotBePosted::withoutPayableAccount($company->name);
        }

        if ($account->type !== AccountType::Liability) {
            throw PaymentCannotBePosted::payableAccountWrongType($account);
        }

        return $this->assertPostable($account, 'payable');
    }

    /**
     * The account a withholding payment credits with the tax it withheld.
     *
     * Always the company's `wht_payable` system account (ADR 0021 §A5), resolved by key. Mirrors
     * `payableAccountFor()`'s missing / wrong-type / not-postable checks exactly. Called only when the payment
     * actually withholds, so a non-withholding company never needs a WHT Payable account.
     */
    public function whtPayableAccountFor(Company $company): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->withSystemKey(Account::WHT_PAYABLE)
            ->first();

        if ($account === null) {
            throw PaymentCannotBePosted::withoutWhtPayableAccount($company->name);
        }

        if ($account->type !== AccountType::Liability) {
            throw PaymentCannotBePosted::whtPayableAccountWrongType($account);
        }

        return $this->assertPostable($account, 'WHT payable');
    }

    /**
     * The caller-named funding account, validated Asset + postable + same-company.
     *
     * Re-read fresh from the database within the company's scope, not trusted from the passed-in object: the
     * caller's instance may be stale (an account archived since it was chosen), and row level security cannot
     * catch the cross-company case anyway — both companies share a `tenant_id`, so the company scope is the
     * only thing that separates a sibling's account. A sibling or hard-deleted account falls out of scope and
     * reads as `null` — the same refusal from the payment's point of view (mirror `BillPostingMap`'s
     * `orderedByCode` re-fetch, `BillPostingMap.php:249-275`).
     */
    private function assertBankAccount(Company $company, Account $bankAccount): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($bankAccount->getKey())
            ->first();

        if ($account === null) {
            throw PaymentCannotBePosted::accountOutsideCompany('bank', (string) $bankAccount->getKey());
        }

        if ($account->type !== AccountType::Asset) {
            throw PaymentCannotBePosted::bankAccountWrongType($account);
        }

        return $this->assertPostable($account, 'bank');
    }

    private function assertPostable(Account $account, string $role): Account
    {
        if (! $account->acceptsPostings()) {
            throw PaymentCannotBePosted::accountNotPostable($role, $account);
        }

        return $account;
    }

    private function line(
        Account $account,
        Money $amount,
        string $description,
        ?string $branchId,
        bool $creditSide,
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
