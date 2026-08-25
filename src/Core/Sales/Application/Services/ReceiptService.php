<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\Services\DocumentNumberService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\ReceiptAllocation;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Record a customer receipt, allocate it across the customer's issued invoices, and post it — one transaction.
 *
 * The mirror of `SalesInvoiceService::issue()` on the receiving side, and it follows that method's discipline
 * exactly: *everything that can refuse runs before anything is reserved*, and the only checks that hold under
 * concurrency are taken under a row lock inside the transaction.
 *
 * RECORD-AND-ALLOCATE IS ATOMIC (Gate-1 #2)
 * -----------------------------------------
 * There is no `record` that leaves a receipt unallocated. A receipt is recorded, fully allocated
 * (Σ allocations = amount, refused otherwise) and posted together, or none of it happens. Accepting a
 * remainder would be unallocated credit-on-account, a deferred feature this wave must not half-build.
 *
 * WHY THE RECEIPT IS POSTED BEFORE IT IS INSERTED
 * -----------------------------------------------
 * A posted receipt is immutable from the moment it exists — the trigger freezes every column, including
 * `journal_entry_id`, because there is no draft state to relax the freeze during (contrast the invoice, whose
 * trigger lets the draft → issued update through). So the whole posted row has to be written in one INSERT,
 * with its ledger link already set. That means the entry must exist first: the receipt and its allocations are
 * built in memory (the id assigned up front so the allocations can reference it and the entry can cite it as
 * its source), the posting map reads those in-memory relations, `PostingService` posts the entry, and only
 * then are the receipt and its lines inserted — carrying the `journal_entry_id` the entry just produced.
 *
 * TWO NUMBERS, TWO SEQUENCES
 * --------------------------
 * The receipt takes `RCT-…` from its own counter; its journal entry takes `JV-…` from the journal-voucher
 * counter, selected through `JournalEntryData::documentType`. A shared counter would leave the receipt series
 * running 1, 3, 5 — the gap `requiresGaplessNumbering()` promises never to leave. The number is reserved
 * inside the transaction so a rollback returns it.
 *
 * NO OVERSELL UNDER A RACE
 * ------------------------
 * Each target invoice is `lockForUpdate()`ed in deterministic id order (so two multi-invoice receipts cannot
 * deadlock), its `amount_due` re-read through the lock, and every allocation re-checked against that fresh
 * figure — never the one the caller submitted against. The second of two receipts racing one invoice re-reads
 * the now-lower balance and is refused, rather than racing to the `amount_paid <= total` CHECK. The lock
 * produces the readable refusal; the CHECK is the backstop that holds if the service is ever bypassed.
 */
final readonly class ReceiptService
{
    public function __construct(
        private ReceiptPostingMap $postingMap,
        private PostingService $posting,
        private DocumentNumberService $numbers,
        private FiscalCalendarService $calendar,
    ) {}

    public function record(Company $company, ReceiptData $data, ?User $actor = null): CustomerReceipt
    {
        $currency = $company->base_currency_code;

        // 1. The amount, as money. Positive, or refused as a domain error rather than a raw CHECK (AC-1.2).
        $amount = Money::of($data->amount, $currency);

        if (! $amount->isPositive()) {
            throw ReceiptCannotBeRecorded::zeroOrNegativeAmount($data->amount);
        }

        // 2. The customer, provided it belongs to this company (AC-1.3).
        $customer = $this->resolveCustomer($company, $data->customerId);

        // 3. The bank/cash account: in company, postable, asset (AC-1.4).
        $bankAccount = $this->resolveBankAccount($company, $data->bankAccountId);

        // 4. The allocation set, against the pre-read invoices — a readable early refusal before any lock.
        //    The authoritative per-invoice cap is re-checked under the lock below (AC-2.5).
        $allocationAmounts = $this->allocationAmounts($data, $currency);

        $this->assertFullyAllocated($allocationAmounts, $amount);

        $invoices = $this->loadInvoices(array_keys($allocationAmounts));

        foreach ($allocationAmounts as $invoiceId => $lineAmount) {
            $this->assertAllocatable($invoices[$invoiceId] ?? null, $invoiceId, $company, $customer);
        }

        // 5. The calendar, before any number is reserved (AC-3.5).
        $period = $this->calendar->periodFor($company, $data->receiptDate->startOfDay());

        if (! $period->acceptsPostings()) {
            throw ReceiptCannotBeRecorded::intoClosedPeriod($period->label, $period->status);
        }

        $branchId = $this->resolveBranchId($company, $data->branchId);

        return DB::transaction(function () use (
            $company, $data, $customer, $bankAccount, $amount, $allocationAmounts, $period, $branchId, $currency, $actor,
        ): CustomerReceipt {
            // 6. Lock and re-read every target invoice, in deterministic id order to prevent deadlock between
            //    two multi-invoice receipts. The figure the caller saw is never trusted.
            $ids = array_keys($allocationAmounts);
            sort($ids);

            /** @var array<string, SalesInvoice> $locked */
            $locked = [];

            foreach ($ids as $invoiceId) {
                $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

                // Re-assert status and the per-invoice cap against the balance read through the lock — the
                // "re-validate at the moment of commit" discipline. A receipt that landed since the caller
                // opened their screen has already lowered this, and the second receipt is refused here.
                if (! $invoice->status->isCollectable()) {
                    throw ReceiptCannotBeAllocated::toNonCollectableInvoice(
                        $invoice->number ?? $invoiceId,
                        $invoice->status,
                    );
                }

                $lineAmount = $allocationAmounts[$invoiceId];
                $amountDue = Money::of($invoice->amount_due, $currency);

                if ($lineAmount->isGreaterThan($amountDue)) {
                    throw ReceiptCannotBeAllocated::exceedsAmountDue(
                        $invoice->number ?? $invoiceId,
                        $lineAmount->toDecimalString(),
                        $amountDue->toDecimalString(),
                    );
                }

                $locked[$invoiceId] = $invoice;
            }

            // 7. The gapless RCT number, reserved inside the transaction so a rollback returns it.
            $number = $this->numbers->next($company, DocumentType::CustomerReceipt, $period);

            // 8. Build the receipt and its allocations in memory, id assigned up front (see the class docblock).
            $receipt = new CustomerReceipt;
            $receipt->setAttribute($receipt->getKeyName(), $receipt->newUniqueId());
            $receipt->company_id = $company->getKey();
            $receipt->customer_id = $customer->getKey();
            $receipt->branch_id = $branchId;
            $receipt->number = $number;
            $receipt->reference = $data->reference;
            $receipt->receipt_date = $data->receiptDate->startOfDay();
            $receipt->currency_code = $currency;
            $receipt->amount = $this->decimal($amount);
            $receipt->payment_method = $data->paymentMethod;
            $receipt->bank_account_id = $bankAccount->getKey();
            $receipt->status = 'posted';
            $receipt->posted_at = now();
            $receipt->posted_by_id = $actor?->getKey();
            $receipt->created_by_id = $actor?->getKey();

            $allocations = [];

            foreach ($ids as $invoiceId) {
                $allocation = new ReceiptAllocation;
                $allocation->setAttribute($allocation->getKeyName(), $allocation->newUniqueId());
                $allocation->company_id = $company->getKey();
                $allocation->customer_receipt_id = $receipt->getKey();
                $allocation->sales_invoice_id = $invoiceId;
                $allocation->amount = $this->decimal($allocationAmounts[$invoiceId]);
                // The locked invoice, so the posting map resolves the receivable account without a fresh read.
                $allocation->setRelation('invoice', $locked[$invoiceId]);

                $allocations[] = $allocation;
            }

            // Relations the map reads, set in memory so it works on this not-yet-persisted receipt exactly as
            // it does on a fresh one loaded from the database.
            $receipt->setRelation('company', $company);
            $receipt->setRelation('customer', $customer);
            $receipt->setRelation('bankAccount', $bankAccount);
            /** @var EloquentCollection<int, ReceiptAllocation> $allocationCollection */
            $allocationCollection = new EloquentCollection($allocations);
            $receipt->setRelation('allocations', $allocationCollection);

            // 9. Post the ledger entry — JV number and source link. The posting map resolves the single
            //    receivable account (or refuses if the invoices disagree — AC-3.2) and returns the two lines.
            $entry = $this->posting->postNew($company, new JournalEntryData(
                entryDate: $receipt->receipt_date,
                description: LedgerNarration::limit(sprintf('Receipt %s from %s', $number, $customer->name)),
                lines: $this->postingMap->for($receipt),
                reference: $number,
                documentType: DocumentType::JournalVoucher,
                source: SourceDocument::for($receipt),
            ), $actor);

            // 10. Insert the whole posted receipt in one statement, carrying its ledger link — the immutability
            //     trigger freezes it the moment it exists, so there is no second write to set this later.
            $receipt->journal_entry_id = $entry->getKey();
            $receipt->save();

            foreach ($allocations as $allocation) {
                $allocation->save();
            }

            // 11. Update each invoice's payment figures and status. `amount_paid` and `amount_due` are written
            //     together in one save — the `amount_due = total - amount_paid` invariant means neither can be
            //     written without the other — and these are the only mutable columns the invoice's immutability
            //     trigger permits.
            foreach ($ids as $invoiceId) {
                $invoice = $locked[$invoiceId];
                $newPaid = Money::of($invoice->amount_paid, $currency)->plus($allocationAmounts[$invoiceId]);
                $newDue = Money::of($invoice->total, $currency)->minus($newPaid);

                $invoice->amount_paid = $this->decimal($newPaid);
                $invoice->amount_due = $this->decimal($newDue);
                $invoice->status = $newDue->isZero()
                    ? SalesInvoiceStatus::Paid
                    : SalesInvoiceStatus::PartiallyPaid;
                $invoice->save();
            }

            return $receipt->refresh();
        });
    }

    /**
     * The amount to apply to each invoice, keyed by invoice id.
     *
     * Aggregated by invoice, so a caller that names one invoice twice gets one line summing them — which is
     * also what the `(receipt, invoice)` uniqueness index requires. Each line is validated positive here, the
     * readable answer before the `receipt_allocations_amount_positive_check` backstop.
     *
     * @return array<string, Money>
     */
    private function allocationAmounts(ReceiptData $data, string $currency): array
    {
        if ($data->allocations === []) {
            throw ReceiptCannotBeRecorded::withoutAllocations();
        }

        /** @var array<string, Money> $amounts */
        $amounts = [];

        foreach ($data->allocations as $allocation) {
            $amount = Money::of($allocation->amount, $currency);

            if (! $amount->isPositive()) {
                throw ReceiptCannotBeAllocated::zeroOrNegativeLine($allocation->salesInvoiceId, $allocation->amount);
            }

            $amounts[$allocation->salesInvoiceId] = isset($amounts[$allocation->salesInvoiceId])
                ? $amounts[$allocation->salesInvoiceId]->plus($amount)
                : $amount;
        }

        return $amounts;
    }

    /**
     * @param  array<string, Money>  $allocationAmounts
     */
    private function assertFullyAllocated(array $allocationAmounts, Money $amount): void
    {
        $allocated = array_reduce(
            $allocationAmounts,
            static fn (Money $carry, Money $line): Money => $carry->plus($line),
            Money::zero($amount->currency),
        );

        if (! $allocated->equals($amount)) {
            throw ReceiptCannotBeRecorded::overOrUnderAllocated(
                $allocated->toDecimalString(),
                $amount->toDecimalString(),
            );
        }
    }

    /**
     * A pre-lock refusal for an invoice the receipt may not touch: it must exist, belong to this company and
     * this customer, and be a live receivable (AC-2.6/2.7/2.8). The per-invoice cap is not checked here — it is
     * race-sensitive, so it is re-read under the lock inside the transaction.
     */
    private function assertAllocatable(?SalesInvoice $invoice, string $invoiceId, Company $company, Customer $customer): void
    {
        if ($invoice === null) {
            throw ReceiptCannotBeAllocated::unknownInvoice($invoiceId);
        }

        $identifier = $invoice->number ?? $invoiceId;

        if ((string) $invoice->company_id !== (string) $company->getKey()) {
            throw ReceiptCannotBeAllocated::crossCompany($identifier);
        }

        if ((string) $invoice->customer_id !== (string) $customer->getKey()) {
            throw ReceiptCannotBeAllocated::crossCustomer($identifier);
        }

        if (! $invoice->status->isCollectable()) {
            throw ReceiptCannotBeAllocated::toNonCollectableInvoice($identifier, $invoice->status);
        }
    }

    /**
     * The customer, provided it belongs to this company.
     *
     * No `acceptsNewInvoices()` check, unlike issuing: a receipt records money already received, which is
     * owed whether or not the customer is still active. What is refused is a customer of another company, or
     * one that does not exist (AC-1.3).
     */
    private function resolveCustomer(Company $company, string $customerId): Customer
    {
        $customer = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw ReceiptCannotBeRecorded::customerOutsideCompany();
        }

        return $customer;
    }

    /**
     * The asset account the receipt debits — in company, postable, an asset (AC-1.4).
     *
     * Refuses with `ReceiptCannotBeRecorded`, because from the recorder's point of view a bad bank account is
     * a problem with the receipt they are entering, not with a later posting. `ReceiptPostingMap` validates
     * the same account again as its pure-map backstop, raising `ReceiptCannotBePosted` there; by the time it
     * runs, this has already passed.
     */
    private function resolveBankAccount(Company $company, string $bankAccountId): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($bankAccountId)
            ->first();

        if ($account === null) {
            throw ReceiptCannotBeRecorded::bankAccountOutsideCompany();
        }

        if ($account->type !== AccountType::Asset) {
            throw ReceiptCannotBeRecorded::bankAccountWrongType($account);
        }

        if (! $account->acceptsPostings()) {
            throw ReceiptCannotBeRecorded::bankAccountNotPostable($account);
        }

        return $account;
    }

    /**
     * @param  list<string>  $invoiceIds
     * @return array<string, SalesInvoice>
     */
    private function loadInvoices(array $invoiceIds): array
    {
        /** @var array<string, SalesInvoice> $invoices */
        $invoices = SalesInvoice::query()
            ->whereKey($invoiceIds)
            ->get()
            ->keyBy('id')
            ->all();

        return $invoices;
    }

    private function resolveBranchId(Company $company, ?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $belongs = Branch::query()
            ->where('company_id', $company->getKey())
            ->whereKey($branchId)
            ->exists();

        if (! $belongs) {
            throw BusinessRuleViolation::make(
                'receipt-branch-outside-company',
                'That branch belongs to a different company.',
            );
        }

        return $branchId;
    }

    /**
     * A `Money` as a decimal string the type checker accepts as numeric.
     *
     * `Money::toDecimalString()` is typed as a plain `string` — its own docblock explains PHPStan cannot see
     * numeric-ness through `sprintf`. The `is_numeric()` check here *proves* it, exactly as
     * `SalesInvoiceService::decimal()` does, rather than a cast that would only claim it.
     *
     * @return numeric-string
     */
    private function decimal(Money $amount): string
    {
        $value = trim($amount->toDecimalString());

        if (! is_numeric($value)) {
            // Unreachable — `Money` guarantees the format — but the check is what establishes the type, and a
            // silent cast would defeat the point.
            throw BusinessRuleViolation::make(
                'receipt-value-not-a-number',
                sprintf('"%s" is not a number.', $value),
            );
        }

        return $value;
    }
}
