<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Models\SalesInvoice;

/**
 * Turns one apply-credit event into the journal lines that reclassify it — ADR 0016 §D.
 *
 * A pure mapping, to the same contract as `ReceiptPostingMap`: it reads the target invoice and an amount,
 * resolves the two accounts involved, and returns `JournalLineData` for the existing `PostingService` to post.
 * **It writes nothing, posts nothing, and reserves no document number** — so the two-line reclassification can
 * be exercised, and got wrong, without touching the ledger.
 *
 * THE SHAPE OF AN APPLY-CREDIT POSTING
 * ------------------------------------
 * A single reclassification, no cash — the money arrived when the receipt was recorded:
 *
 *   Dr  Customer Advances        <applied>
 *       Cr  Trade Receivables            <applied>
 *
 * The Customer Advances debit draws down the liability the receipt's remainder raised; the Trade Receivables
 * credit reduces the invoice's balance on the same control account the invoice debited when it was issued, so
 * subledger and control keep agreeing. The entry balances by construction: both sides are the same `applied`.
 *
 * ONE JV PER CONSUMED RECORD
 * --------------------------
 * A single apply call may consume several held-credit records (FIFO, §E); each consumed record is its own
 * `credit_application` and so its own journal-entry source document (Problem #1), which is why this map is
 * called once per record with that record's share of the applied amount, never once for the whole call.
 */
final readonly class CreditApplicationPostingMap
{
    public function __construct(
        private InvoicePostingMap $invoiceMap,
    ) {}

    /**
     * The two lines reclassifying `amount` of held credit onto `invoice`.
     *
     * @return list<JournalLineData>
     */
    public function for(SalesInvoice $invoice, Money $amount, string $narration): array
    {
        $advances = $this->customerAdvancesAccountFor((string) $invoice->company_id);
        // Resolved the same way the invoice debited it — customer override, else the system account — so the
        // credit lands on the exact control account the invoice raised (ADR 0016 §D). Reuses the invoice map's
        // resolution, refusing there if the receivable is reclassified, non-postable or unprovisioned.
        $receivable = $this->invoiceMap->receivableAccountFor($invoice);

        return [
            new JournalLineData(
                accountId: (string) $advances->getKey(),
                debit: $amount,
                branchId: $invoice->branch_id,
                description: $narration,
            ),
            new JournalLineData(
                accountId: (string) $receivable->getKey(),
                credit: $amount,
                branchId: $invoice->branch_id,
                description: $narration,
            ),
        ];
    }

    /**
     * The Customer Advances account this event debits (ADR 0016 §A).
     *
     * Resolved by system key, never by code — a company may renumber — and validated a postable liability, the
     * checks a CHECK cannot make because it cannot join to `accounts`. Refused with
     * `withoutCustomerAdvancesAccount()` when a company has none, exactly as the receipt map refuses it on the
     * record side.
     */
    public function customerAdvancesAccountFor(string $companyId): Account
    {
        $account = Account::query()
            ->forCompany($companyId)
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
}
