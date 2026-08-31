<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\Services\DocumentNumberService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\ApplyCreditData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeCancelled;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\CreditApplication;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\ReceiptAllocation;
use Asids\Core\Sales\Domain\Models\ReceiptHeldCredit;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
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
        private CreditApplicationPostingMap $creditApplicationMap,
        private PostingService $posting,
        private DocumentNumberService $numbers,
        private FiscalCalendarService $calendar,
    ) {}

    public function record(Company $company, ReceiptData $data, ?User $actor = null): CustomerReceipt
    {
        $currency = $company->base_currency_code;
        $precision = $company->currency_precision;

        // 1. The amount, as money. Positive, or refused as a domain error rather than a raw CHECK (AC-1.2).
        $amount = Money::of($data->amount, $currency);

        if (! $amount->isPositive()) {
            throw ReceiptCannotBeRecorded::zeroOrNegativeAmount($data->amount);
        }

        // The amount must already be at the company's currency precision (ADR 0016 Gate-2 amendment). A finer
        // value would create a remainder the ledger — which posts at currency_precision — could never match.
        $this->assertAtCurrencyPrecision($amount, $precision);

        // 2. The customer, provided it belongs to this company (AC-1.3).
        $customer = $this->resolveCustomer($company, $data->customerId);

        // 3. The bank/cash account: in company, postable, asset (AC-1.4).
        $bankAccount = $this->resolveBankAccount($company, $data->bankAccountId);

        // 4. The allocation set, against the pre-read invoices — a readable early refusal before any lock.
        //    The authoritative per-invoice cap is re-checked under the lock below (AC-2.5). Each line is held
        //    to the currency precision for the same reason as the amount.
        $allocationAmounts = $this->allocationAmounts($data, $currency, $precision);

        $this->assertNotOverAllocated($allocationAmounts, $amount);

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
            $company, $data, $customer, $bankAccount, $amount, $allocationAmounts, $period, $branchId, $currency, $precision, $actor,
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

            // 12. Hold the remainder as customer advances (ADR 0016 §C). The remainder is `amount − Σ
            //     allocations`, non-negative by construction because over-allocation was refused above. When it
            //     is zero no record is created — identical to a fully-allocated receipt — and the DB's
            //     `original_amount > 0` CHECK is the backstop. The GL side of this remainder is the Customer
            //     Advances credit line the posting map already emitted.
            $allocated = array_reduce(
                $allocationAmounts,
                static fn (Money $carry, Money $line): Money => $carry->plus($line),
                Money::zero($currency),
            );

            // Held at the company's currency precision, so the subledger record and the Customer Advances
            // posting line (also at currency_precision) agree exactly and the entry balances (ADR 0016 Gate-2
            // amendment). A no-op when amount and allocations are already at precision — which the input
            // validation above guarantees — but applied explicitly so the invariant does not depend on it.
            $remainder = $amount->minus($allocated)->roundedTo($precision);

            if ($remainder->isPositive()) {
                $heldCredit = new ReceiptHeldCredit;
                $heldCredit->setAttribute($heldCredit->getKeyName(), $heldCredit->newUniqueId());
                $heldCredit->company_id = $company->getKey();
                $heldCredit->customer_id = $customer->getKey();
                $heldCredit->customer_receipt_id = $receipt->getKey();
                $heldCredit->currency_code = $currency;
                $heldCredit->original_amount = $this->decimal($remainder);
                $heldCredit->applied_amount = $this->decimal(Money::zero($currency));
                $heldCredit->remaining_amount = $this->decimal($remainder);
                $heldCredit->status = ReceiptHeldCredit::STATUS_ACTIVE;
                $heldCredit->created_by_id = $actor?->getKey();
                $heldCredit->save();
            }

            return $receipt->refresh();
        });
    }

    /**
     * Apply a customer's held credit to one of that customer's later invoices — ADR 0016 §D, §E, §H.
     *
     * No cash arrives: the money was booked when the source receipt was recorded, leaving a Customer Advances
     * liability. Applying it reclassifies that liability onto the invoice — Dr Customer Advances / Cr Trade
     * Receivables — and moves the invoice's balance forward exactly as a receipt allocation would.
     *
     * FIFO, OR A NAMED SOURCE
     * -----------------------
     * By default the credit is drawn from the customer's active held records oldest-first, by the source
     * receipt's `receipt_date` then `number`, consuming as many records as the requested amount needs. A named
     * `sourceReceiptId` overrides FIFO and draws from that receipt alone — a named source that falls short is
     * refused rather than spilling into other records. Either way the customer is taken from the *target
     * invoice*, so credit never crosses a customer or a company.
     *
     * ONE APPLICATION, ONE JV, PER CONSUMED RECORD
     * --------------------------------------------
     * Each consumed held-credit record produces its own `credit_application` row and its own reclassification
     * JV, because the source-uniqueness index permits one non-reversing posting per source document — a second
     * apply against one held credit must carry its own source (Problem #1). Each application is built in memory
     * with its id assigned up front so its JV can cite it as source, then inserted whole with the resulting
     * `journal_entry_id`: the `credit_applications` full-freeze trigger refuses any later UPDATE.
     *
     * WHAT HOLDS UNDER CONCURRENCY
     * ----------------------------
     * The candidate held-credit rows are locked first, in ascending id order (so two applies cannot deadlock),
     * then the target invoice — the global order receipts → held-credits → invoices, with apply never touching
     * the receipt row. Two applies of one record contend on its lock: one decrements `remaining_amount` and
     * commits, the other re-reads the now-lower figure and either fits or is refused `insufficientCredit`.
     * Available credit cannot go negative — the lock serialises the decrement and the `remaining_amount >= 0`
     * CHECK is the backstop if the service is ever bypassed.
     *
     * @return list<CreditApplication> one per consumed held-credit record
     */
    public function applyCredit(Company $company, ApplyCreditData $data, ?User $actor = null): array
    {
        $currency = $company->base_currency_code;
        $precision = $company->currency_precision;

        // 1. The requested amount: positive, and at the company's currency precision (Gate-2 amendment) — a
        //    finer value could never be represented in the ledger the reclassification posts to.
        $requested = Money::of($data->amount, $currency);

        if (! $requested->isPositive()) {
            throw ReceiptCannotBeAllocated::zeroOrNegativeLine($data->salesInvoiceId, $data->amount);
        }

        $this->assertAtCurrencyPrecision($requested, $precision);

        // 2. The target invoice. `firstOrFail` so a foreign tenant — for whom row level security hides the row —
        //    gets a not-found rather than a misleading domain refusal, matching the tenant-isolation guard.
        $invoice = SalesInvoice::query()->whereKey($data->salesInvoiceId)->firstOrFail();

        $identifier = $invoice->number ?? $data->salesInvoiceId;

        // Two companies in one workspace share a `tenant_id`, so row level security is satisfied by either's
        // rows; only this comparison stops credit reaching a sibling company's invoice.
        if ((string) $invoice->company_id !== (string) $company->getKey()) {
            throw ReceiptCannotBeAllocated::crossCompany($identifier);
        }

        $customerId = (string) $invoice->customer_id;

        return DB::transaction(function () use (
            $company, $data, $invoice, $identifier, $requested, $currency, $customerId, $actor,
        ): array {
            // 3. Lock the candidate held-credit records (held-credits before invoices, ascending id within the
            //    table — §H). FIFO is the *consumption* order, computed in memory over the locked set below.
            $candidates = $this->lockCandidateHeldCredits($company, $customerId, $data->sourceReceiptId, $identifier);

            // 4. Enough applicable credit? The exhausted-set refusal, before the invoice is even locked, so a
            //    shortfall writes nothing. A named source that fell short filtered itself out above.
            $available = array_reduce(
                $candidates,
                static fn (Money $carry, ReceiptHeldCredit $held): Money => $carry->plus(Money::of($held->remaining_amount, $currency)),
                Money::zero($currency),
            );

            if ($requested->isGreaterThan($available)) {
                throw ReceiptCannotBeAllocated::insufficientCredit(
                    $requested->toDecimalString(),
                    $available->toDecimalString(),
                );
            }

            // 5. Lock and re-read the target invoice (after the held credits, per the order). Its status and
            //    outstanding balance are re-checked against the figure read through the lock, never the caller's.
            $lockedInvoice = SalesInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedInvoice->status->isCollectable()) {
                throw ReceiptCannotBeAllocated::toNonCollectableInvoice($identifier, $lockedInvoice->status);
            }

            $amountDue = Money::of($lockedInvoice->amount_due, $currency);

            if ($requested->isGreaterThan($amountDue)) {
                throw ReceiptCannotBeAllocated::exceedsAmountDue(
                    $identifier,
                    $requested->toDecimalString(),
                    $amountDue->toDecimalString(),
                );
            }

            // 6. Consume FIFO across the locked set — one application + one JV per record (Problem #1).
            $narration = LedgerNarration::limit(sprintf('Credit applied to invoice %s', $identifier));

            /** @var list<CreditApplication> $applications */
            $applications = [];
            $remainingToApply = $requested;

            foreach ($candidates as $held) {
                if (! $remainingToApply->isPositive()) {
                    break;
                }

                $heldRemaining = Money::of($held->remaining_amount, $currency);
                $take = $remainingToApply->isGreaterThan($heldRemaining) ? $heldRemaining : $remainingToApply;

                // Built in memory, id assigned up front so its JV can cite it as source and the row carries the
                // resulting `journal_entry_id` in one INSERT (the full-freeze trigger refuses a later UPDATE).
                $application = new CreditApplication;
                $application->setAttribute($application->getKeyName(), $application->newUniqueId());
                $application->company_id = $company->getKey();
                $application->customer_id = $customerId;
                $application->receipt_held_credit_id = $held->getKey();
                $application->sales_invoice_id = $lockedInvoice->getKey();
                $application->currency_code = $currency;
                $application->amount = $this->decimal($take);
                $application->applied_at = now();
                $application->applied_by_id = $actor?->getKey();
                $application->created_by_id = $actor?->getKey();

                $entry = $this->posting->postNew($company, new JournalEntryData(
                    entryDate: CarbonImmutable::now()->startOfDay(),
                    description: $narration,
                    lines: $this->creditApplicationMap->for($lockedInvoice, $take, $narration),
                    reference: $identifier,
                    documentType: DocumentType::JournalVoucher,
                    source: SourceDocument::for($application),
                ), $actor);

                $application->journal_entry_id = $entry->getKey();
                $application->save();

                $applications[] = $application;

                // Decrement the held record, delta: applied up, remaining down, written together (the balance-tie
                // CHECK). `status` stays active — a fully-consumed record simply drops out of the FIFO filter.
                $newApplied = Money::of($held->applied_amount, $currency)->plus($take);
                $newRemaining = Money::of($held->original_amount, $currency)->minus($newApplied);

                $held->applied_amount = $this->decimal($newApplied);
                $held->remaining_amount = $this->decimal($newRemaining);
                $held->save();

                $remainingToApply = $remainingToApply->minus($take);
            }

            // 7. Move the target invoice forward by the total applied — the mirror of record()'s split.
            //    `amount_paid` and `amount_due` are written together (the `amount_due = total - amount_paid`
            //    invariant), status to Paid when settled else PartiallyPaid, the only columns the invoice's
            //    immutability trigger permits.
            $newPaid = Money::of($lockedInvoice->amount_paid, $currency)->plus($requested);
            $newDue = Money::of($lockedInvoice->total, $currency)->minus($newPaid);

            $lockedInvoice->amount_paid = $this->decimal($newPaid);
            $lockedInvoice->amount_due = $this->decimal($newDue);
            $lockedInvoice->status = $newDue->isZero()
                ? SalesInvoiceStatus::Paid
                : SalesInvoiceStatus::PartiallyPaid;
            $lockedInvoice->save();

            return $applications;
        });
    }

    /**
     * Cancel a posted receipt, reversing its posting and restoring the invoices it paid.
     *
     * The counterpart to `record()`, and deliberately not its undo. Nothing is deleted and nothing about the
     * receipt header is edited beyond the transition itself: it keeps its number, its dates and its figures,
     * its allocation rows stay as permanent history, its original entry stays in the ledger, and a mirror entry
     * is posted alongside. An auditor sees the document, the posting and the correction.
     *
     * WHICH PERIOD HAS TO BE OPEN
     * ---------------------------
     * The reversal's, not the receipt's. `PostingService::reverse()` dates the mirror today rather than at the
     * original's date, because backdating a correction into a closed period is precisely what closing prevents.
     * So a receipt from a closed month may still be cancelled today; what refuses a cancellation is today's
     * period being closed. Checked here as well as inside the posting service so the caller is told about the
     * *receipt* rather than about a journal entry they never asked about.
     *
     * BALANCE RESTORATION IS A DELTA, NEVER A SNAPSHOT (ADR 0015 §C, the correctness pivot)
     * ------------------------------------------------------------------------------------
     * For each invoice this receipt allocated to, its own allocation amount is subtracted from the invoice's
     * *current* `amount_paid`, re-read through the lock — never a remembered "what the invoice looked like
     * before this receipt". A later receipt's still-live contribution to the same invoice is therefore
     * preserved: cancelling A when A(400)+B(600) took an invoice to Paid leaves it at 600/PartiallyPaid with B
     * intact, not zeroed. This is `record()`'s forward `plus` run in reverse as `minus`.
     *
     * WHAT HOLDS UNDER CONCURRENCY
     * ----------------------------
     * The receipt row is locked first, then every allocated invoice in ascending id order — the same total
     * order `record()` uses, so a cancel racing a record cannot deadlock. A second cancellation of the same
     * receipt queues on the receipt lock, re-reads a now-cancelled row, and is refused by `alreadyCancelled()`
     * before it can reach `PostingService::reverse()`. The lock produces the readable refusal; the finality
     * trigger and `reverse()`'s own already-reversed guard are the backstops.
     */
    public function cancel(CustomerReceipt $receipt, string $reason, ?User $actor = null): CustomerReceipt
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ReceiptCannotBeCancelled::withoutReason($receipt->number ?? (string) $receipt->getKey());
        }

        return DB::transaction(function () use ($receipt, $reason, $actor): CustomerReceipt {
            // Locked before anything is read, so a concurrent attempt queues here rather than racing to the
            // finality trigger. Re-read through the lock: the in-memory instance may predate another request.
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing(['company', 'journalEntry', 'allocations']);

            $identifier = $locked->number ?? (string) $locked->getKey();

            if ($locked->status === 'cancelled') {
                throw ReceiptCannotBeCancelled::alreadyCancelled($identifier);
            }

            // Defensive: no third status is reachable under the two-value CHECK (Gate-1 #5).
            if ($locked->status !== 'posted') {
                throw ReceiptCannotBeCancelled::notPosted($identifier, $locked->status);
            }

            $entry = $locked->journalEntry;

            if ($entry === null) {
                throw ReceiptCannotBeCancelled::withoutJournalEntry($identifier);
            }

            // Two companies in one workspace share a `tenant_id`, so row level security is satisfied by either
            // one's entries. Only this comparison stops a reversal landing in a sibling's ledger.
            if ((string) $entry->company_id !== (string) $locked->company_id) {
                throw ReceiptCannotBeCancelled::journalEntryOutsideCompany($identifier);
            }

            // Compared to `Posted` explicitly rather than asking `isPosted()`, which is true for a reversed
            // entry too. Using it here would let a second cancellation reach `PostingService`, which refuses it
            // — but with a message about a journal entry rather than about this receipt.
            if ($entry->status !== JournalEntryStatus::Posted) {
                throw ReceiptCannotBeCancelled::journalEntryNotReversible(
                    $identifier,
                    $entry->number ?? (string) $entry->getKey(),
                    $entry->status->value,
                );
            }

            $reversalDate = CarbonImmutable::now()->startOfDay();
            $period = $this->calendar->periodFor($locked->company, $reversalDate);

            if (! $period->acceptsPostings()) {
                throw ReceiptCannotBeCancelled::intoClosedPeriod($identifier, $period->label, $period->status);
            }

            $currency = $locked->currency_code;

            // This receipt's own allocation against each invoice, read from the untouched allocation rows —
            // never recomputed. These are the deltas the restore subtracts.
            /** @var array<string, Money> $allocationByInvoice */
            $allocationByInvoice = [];

            foreach ($locked->allocations as $allocation) {
                $allocationByInvoice[(string) $allocation->sales_invoice_id] = Money::of($allocation->amount, $currency);
            }

            // Lock this receipt's held-credit record — after the receipt, before the invoices (the global order
            // receipts → held-credits → invoices, §H). A receipt whose credit was already applied is refused
            // here, before anything is reversed: `reverse()` mirrors the entry whole, so reversing the full
            // Customer Advances credit while part of it has been reclassified out by an apply would over-reverse
            // the subledger (§G Case 2). Untouched credit is delta-zeroed after the reversal, below.
            $heldCredit = ReceiptHeldCredit::query()
                ->where('customer_receipt_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($heldCredit !== null) {
                $applied = Money::of($heldCredit->applied_amount, $currency);

                if ($applied->isPositive()) {
                    throw ReceiptCannotBeCancelled::heldCreditAlreadyApplied($identifier, $applied->toDecimalString());
                }
            }

            // Lock and re-read every allocated invoice in deterministic id order — `record()`'s ordering, run
            // in the reverse direction, so a cancel racing a record cannot deadlock.
            $ids = array_keys($allocationByInvoice);
            sort($ids);

            /** @var array<string, SalesInvoice> $lockedInvoices */
            $lockedInvoices = [];

            foreach ($ids as $invoiceId) {
                $lockedInvoices[$invoiceId] = SalesInvoice::query()
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Reverse the posting: a mirror JV, the original marked Reversed, the RCT untouched (§F).
            $this->posting->reverse($entry, $reason, $reversalDate, $actor);

            // Delta-restore each locked invoice (§C). `amount_paid` and `amount_due` are written together in
            // one save — the `amount_due = total - amount_paid` invariant means neither can be written without
            // the other — and these are the only mutable columns the invoice's immutability trigger permits.
            foreach ($ids as $invoiceId) {
                $invoice = $lockedInvoices[$invoiceId];
                $currentPaid = Money::of($invoice->amount_paid, $currency);
                $allocation = $allocationByInvoice[$invoiceId];

                $newPaid = $currentPaid->minus($allocation);

                if ($newPaid->isNegative()) {
                    throw ReceiptCannotBeCancelled::wouldReverseBelowZero(
                        $invoice->number ?? $invoiceId,
                        $currentPaid->toDecimalString(),
                        $allocation->toDecimalString(),
                    );
                }

                $newDue = Money::of($invoice->total, $currency)->minus($newPaid);

                $invoice->amount_paid = $this->decimal($newPaid);
                $invoice->amount_due = $this->decimal($newDue);
                $invoice->status = $newPaid->isZero()
                    ? SalesInvoiceStatus::Issued
                    : SalesInvoiceStatus::PartiallyPaid;
                $invoice->save();
            }

            // Delta-zero the untouched held credit alongside the invoice restore — the credit-side analogue of
            // the delta-restore, using the record's own current remaining, never a snapshot (§G Case 1). The GL
            // side is already unwound: `reverse()` mirrored the whole entry, including the Customer Advances
            // credit. `remaining` and `applied` are written together so the balance-tie CHECK holds — the
            // remainder is consumed into `applied` (applied → original), which is the only shape the tie and the
            // cancelled ⇒ remaining = 0 CHECK jointly permit while `original_amount` stays frozen.
            if ($heldCredit !== null) {
                $remaining = Money::of($heldCredit->remaining_amount, $currency);
                $newApplied = Money::of($heldCredit->applied_amount, $currency)->plus($remaining);

                $heldCredit->applied_amount = $this->decimal($newApplied);
                $heldCredit->remaining_amount = $this->decimal($remaining->minus($remaining));
                $heldCredit->status = ReceiptHeldCredit::STATUS_CANCELLED;
                $heldCredit->save();
            }

            // One save, like recording. The tie-to-status CHECK means a status written without the metadata —
            // or metadata without the status — is refused by the database rather than merely avoided here.
            $locked->status = 'cancelled';
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->cancelled_by_id = $actor?->getKey();
            $locked->save();

            return $locked->refresh();
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
    private function allocationAmounts(ReceiptData $data, string $currency, int $precision): array
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

            // Held to the currency precision, like the receipt amount (ADR 0016 Gate-2 amendment): a finer
            // allocation could leave a remainder the ledger cannot represent.
            $this->assertAtCurrencyPrecision($amount, $precision);

            $amounts[$allocation->salesInvoiceId] = isset($amounts[$allocation->salesInvoiceId])
                ? $amounts[$allocation->salesInvoiceId]->plus($amount)
                : $amount;
        }

        return $amounts;
    }

    /**
     * Refuse an amount finer than the company's currency precision (ADR 0016 Gate-2 amendment).
     *
     * `roundedTo($precision)` at the currency's own precision is idempotent for a value already at it, so the
     * equality holds exactly when the amount has no sub-precision digits. Refused rather than rounded, so a
     * mistyped `1000.3333` in a two-decimal currency is corrected at the source rather than posting a figure
     * that disagrees with what was entered.
     */
    private function assertAtCurrencyPrecision(Money $amount, int $precision): void
    {
        if (! $amount->roundedTo($precision)->equals($amount)) {
            throw ReceiptCannotBeRecorded::amountExceedsCurrencyPrecision($amount->toDecimalString(), $precision);
        }
    }

    /**
     * Refuse only over-allocation (ADR 0016 §C, formerly `assertFullyAllocated`).
     *
     * `Σ allocations > amount` applies more than was received and still refuses (Gate-1 #5). `Σ ≤ amount` is
     * accepted: an exact allocation posts two lines as before, and a shortfall leaves a remainder held as
     * customer advances (ADR 0016). The empty-allocation refusal stays separate (`allocationAmounts()`,
     * Gate-1 #3) — a remainder is only ever permitted on an otherwise-allocated receipt.
     *
     * @param  array<string, Money>  $allocationAmounts
     */
    private function assertNotOverAllocated(array $allocationAmounts, Money $amount): void
    {
        $allocated = array_reduce(
            $allocationAmounts,
            static fn (Money $carry, Money $line): Money => $carry->plus($line),
            Money::zero($amount->currency),
        );

        if ($allocated->isGreaterThan($amount)) {
            throw ReceiptCannotBeRecorded::overAllocated(
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
     * The held-credit records this apply may consume, locked and in FIFO order — ADR 0016 §E, §H.
     *
     * A named source is resolved and locked alone; if it belongs to another customer it is refused as a
     * cross-customer attempt (credit never crosses a customer, §K#5), and if it is cancelled or exhausted it
     * falls out as an empty set (surfacing as `insufficientCredit`, never a silent spill into other records).
     *
     * Without a named source, the customer's active records with credit remaining are selected, locked in
     * ascending id order (the deadlock-free acquisition order), re-filtered under the lock, then sorted by the
     * source receipt's `receipt_date` then `number` — the business-precedence order, distinct from the
     * lock-acquisition order.
     *
     * @return list<ReceiptHeldCredit>
     */
    private function lockCandidateHeldCredits(
        Company $company,
        string $customerId,
        ?string $sourceReceiptId,
        string $invoiceIdentifier,
    ): array {
        $currency = $company->base_currency_code;

        if ($sourceReceiptId !== null) {
            $held = ReceiptHeldCredit::query()
                ->where('company_id', $company->getKey())
                ->where('customer_receipt_id', $sourceReceiptId)
                ->lockForUpdate()
                ->first();

            if ($held === null) {
                return [];
            }

            // Checked before status/remaining so a deliberate cross-customer attempt is named as such rather
            // than reported as "insufficient".
            if ((string) $held->customer_id !== $customerId) {
                throw ReceiptCannotBeAllocated::crossCustomer($invoiceIdentifier);
            }

            $isUsable = $held->status === ReceiptHeldCredit::STATUS_ACTIVE
                && Money::of($held->remaining_amount, $currency)->isPositive();

            return $isUsable ? [$held] : [];
        }

        /** @var list<string> $ids */
        $ids = ReceiptHeldCredit::query()
            ->where('company_id', $company->getKey())
            ->where('customer_id', $customerId)
            ->where('status', ReceiptHeldCredit::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        /** @var list<ReceiptHeldCredit> $locked */
        $locked = [];

        foreach ($ids as $id) {
            $held = ReceiptHeldCredit::query()
                ->whereKey($id)
                ->with('receipt')
                ->lockForUpdate()
                ->first();

            if ($held === null) {
                continue;
            }

            if ($held->status === ReceiptHeldCredit::STATUS_ACTIVE
                && Money::of($held->remaining_amount, $currency)->isPositive()) {
                $locked[] = $held;
            }
        }

        usort($locked, static function (ReceiptHeldCredit $a, ReceiptHeldCredit $b): int {
            $dateA = $a->receipt->receipt_date;
            $dateB = $b->receipt->receipt_date;

            if (! $dateA->equalTo($dateB)) {
                return $dateA->lessThan($dateB) ? -1 : 1;
            }

            return ($a->receipt->number ?? '') <=> ($b->receipt->number ?? '');
        });

        return $locked;
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
