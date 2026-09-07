<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
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
     * @return list<JournalLineData>
     */
    public function for(Company $company, Account $bankAccount, Money $total, Supplier $supplier): array
    {
        $payable = $this->payableAccountFor($company);
        $bank = $this->assertBankAccount($company, $bankAccount);

        $narration = LedgerNarration::limit(sprintf('Payment to %s', $supplier->name));

        return [
            $this->line($payable, $total, $supplier, $narration, creditSide: false),
            $this->line($bank, $total, $supplier, $narration, creditSide: true),
        ];
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
        Supplier $supplier,
        string $description,
        bool $creditSide,
    ): JournalLineData {
        return new JournalLineData(
            accountId: (string) $account->getKey(),
            debit: $creditSide ? null : $amount,
            credit: $creditSide ? $amount : null,
            branchId: null,
            description: $description,
        );
    }
}
