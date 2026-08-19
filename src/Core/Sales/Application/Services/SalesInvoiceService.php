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
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\InvalidInvoiceDiscount;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBeCancelled;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBeIssued;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft sales invoices: create, change, delete.
 *
 * Milestone 4 stops at drafts. Issuing, posting and cancellation are Milestone 5, and nothing here moves an
 * invoice out of `draft` — the database CHECKs from Stage 1 would refuse it if it tried.
 *
 * Three things are worth naming before the code.
 *
 * **Lines are replaced wholesale, never diffed.** `JournalService::updateDraft()` established this and the
 * reasoning carries over exactly: an invoice is a document, not a collection that accretes. "These are its
 * lines now" is what a user means when they save the form, and matching submitted rows against stored ones by
 * position is how a reordered line silently becomes an edit of a different account.
 *
 * **Tax is resolved by code and date, never by id.** The line names `VAT`; `TaxRateResolver` decides which
 * effective-dated row that means for this invoice's date. Accepting a tax-code id instead would let a caller
 * pick an expired or future row and bypass the only mechanism that knows which is correct.
 *
 * **Cross-company validation is not redundant with row level security.** Two companies in one workspace share
 * a `tenant_id`, so the policy is satisfied by either one's customers, accounts and tax codes. Only the
 * explicit company comparison stops an invoice citing its sibling's ledger.
 */
final readonly class SalesInvoiceService
{
    public function __construct(
        private TaxRateResolver $resolver,
        private InvoiceTotalsCalculator $totals,
        private InvoicePostingMap $postingMap,
        private PostingService $posting,
        private DocumentNumberService $numbers,
        private FiscalCalendarService $calendar,
    ) {}

    public function createDraft(Company $company, SalesInvoiceData $data, ?string $createdById = null): SalesInvoice
    {
        $customer = $this->resolveCustomer($company, $data->customerId, forNewInvoice: true);
        $dueDate = $data->dueDate ?? $customer->dueDateFor($data->invoiceDate);

        $this->assertDates($data->invoiceDate, $dueDate);

        return DB::transaction(function () use ($company, $data, $customer, $dueDate, $createdById): SalesInvoice {
            $invoice = new SalesInvoice;

            $invoice->company_id = $company->getKey();
            $invoice->customer_id = $customer->getKey();
            $invoice->branch_id = $this->resolveBranchId($company, $data->branchId);
            $invoice->reference = $data->reference;
            $invoice->invoice_date = $data->invoiceDate;
            $invoice->due_date = $dueDate;
            $invoice->currency_code = $company->base_currency_code;
            $invoice->notes = $data->notes;
            $invoice->terms = $data->terms;

            // Set explicitly rather than left to the column defaults, so an unsaved instance reads back the
            // same as a saved one under `Model::shouldBeStrict()` — the trap Phase 1 hit on
            // `must_change_password` and Phase 2 hit again on `is_closed`.
            $invoice->status = SalesInvoiceStatus::Draft;
            $invoice->exchange_rate = null;
            $invoice->number = null;
            $invoice->issued_at = null;
            $invoice->journal_entry_id = null;
            $invoice->created_by_id = $createdById;

            // Not saved here. `replaceLines()` computes the totals from the submitted data, assigns them, and
            // saves the invoice exactly once — so the audit trail records one creation carrying the real
            // figures rather than a creation at zero followed immediately by a change.
            $this->replaceLines($invoice, $company, $data->lines, $data->discountAmount);

            return $invoice->refresh();
        });
    }

    /**
     * Change a draft.
     *
     * Takes an array rather than a DTO, following `ChartOfAccountsService::update()` and `TaxCodeService`,
     * because `array_key_exists()` is what distinguishes "leave this alone" from "set this to null". On an
     * invoice that matters for the header discount, the branch and the reference: each is nullable, and a
     * signature that could not express clearing would make all three permanent once set.
     *
     * Recognised keys: `reference`, `notes`, `terms`, `customer_id`, `invoice_date`, `due_date`, `branch_id`,
     * `discount_amount`, `lines`. Anything else is ignored rather than rejected, as the chart of accounts does.
     *
     * Supplying `lines` replaces every line. Omitting it leaves them, and recomputes the totals anyway —
     * because a changed `invoice_date` can change which tax rate applies even when no line moved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(SalesInvoice $invoice, array $attributes): SalesInvoice
    {
        $this->assertEditable($invoice);

        $company = $invoice->company;

        $customer = array_key_exists('customer_id', $attributes)
            ? $this->resolveCustomer($company, (string) $attributes['customer_id'], forNewInvoice: true)
            : $invoice->customer;

        $invoiceDate = array_key_exists('invoice_date', $attributes)
            ? CarbonImmutable::parse((string) $attributes['invoice_date'])->startOfDay()
            : $invoice->invoice_date->startOfDay();

        // An explicitly supplied null re-derives from the customer's terms rather than clearing the column,
        // which is not nullable. "Use the default" is the only sensible reading of a cleared due date.
        $dueDate = array_key_exists('due_date', $attributes)
            ? ($attributes['due_date'] === null
                ? $customer->dueDateFor($invoiceDate)
                : CarbonImmutable::parse((string) $attributes['due_date'])->startOfDay())
            : $invoice->due_date->startOfDay();

        $this->assertDates($invoiceDate, $dueDate);

        return DB::transaction(function () use ($invoice, $company, $customer, $invoiceDate, $dueDate, $attributes): SalesInvoice {
            $invoice->fill(array_intersect_key($attributes, array_flip(['reference', 'notes', 'terms'])));

            $invoice->customer_id = $customer->getKey();
            $invoice->invoice_date = $invoiceDate;
            $invoice->due_date = $dueDate;

            if (array_key_exists('branch_id', $attributes)) {
                $invoice->branch_id = $attributes['branch_id'] === null
                    ? null
                    : $this->resolveBranchId($company, (string) $attributes['branch_id']);
            }

            // Deliberately not saved here either — see `createDraft`. One save per call, done by
            // `replaceLines()` once the totals are known.

            /** @var numeric-string|null $discount */
            $discount = array_key_exists('discount_amount', $attributes)
                ? ($attributes['discount_amount'] === null ? null : trim((string) $attributes['discount_amount']))
                : $this->existingHeaderDiscount($invoice);

            $lines = array_key_exists('lines', $attributes)
                ? $this->lineDataFrom($attributes['lines'])
                : $this->lineDataFromExisting($invoice);

            $this->replaceLines($invoice, $company, $lines, $discount);

            return $invoice->refresh();
        });
    }

    /**
     * Turn a draft into an issued invoice, posted to the ledger.
     *
     * The moment the document becomes real: it gets a number a customer will quote, a date it was issued on, and
     * an entry in a ledger that will never let it be edited again. All of it commits together or none of it does.
     *
     * WHY EVERYTHING IS RE-VALIDATED HERE
     * -----------------------------------
     * A draft written in March and issued in June has had three months in which its customer could be archived,
     * its revenue account reclassified, its tax code's output account cleared, or its period closed. Draft-time
     * validation says what was true when it was written; approved decision B5 says the only validation that
     * matters is the one at the moment of posting. So every account, the customer and the calendar are checked
     * again, and none of the checks is skipped on the grounds that `createDraft` already made it.
     *
     * WHAT IS NOT RECOMPUTED
     * ----------------------
     * The money. `line_subtotal` and `tax_amount` were rounded to the currency when the draft was written, and
     * the header CHECK holds `total = subtotal + tax_total`. Re-resolving a tax rate here would silently reprice
     * a document the customer has already seen; recomputing the totals would risk a different rounding path
     * producing an entry that does not balance. The posting map sums the stored values, which is why the entry
     * balances by construction rather than by luck.
     *
     * TWO NUMBERS, TWO SEQUENCES
     * --------------------------
     * The invoice takes `INV-…` from the `sales_invoice` counter. Its journal entry takes `JV-…` from the
     * journal voucher counter, because `document_sequences` is keyed on the document type and a single counter
     * feeding both would hand the invoice 0001 and its own entry 0002 — invoice numbers running 1, 3, 5, which
     * is precisely the gap `requiresGaplessNumbering()` promises never to leave. The entry is still identifiably
     * this invoice's: it carries the invoice as its source document, and the unique index over `source_id` is
     * what makes a second posting impossible.
     *
     * ORDER MATTERS, AND THE ORDER IS DELIBERATE
     * ------------------------------------------
     * Everything that can refuse runs before anything is reserved. A closed period, an archived account or a
     * zero total costs no document number, because `document_sequences` is incremented under a row lock inside
     * this transaction — a rollback returns the number, but only if we never needed it in the first place is the
     * failure free of contention.
     */
    public function issue(SalesInvoice $invoice, ?User $actor = null): SalesInvoice
    {
        $invoice->loadMissing(['company', 'customer', 'lines.taxCode']);

        $company = $invoice->company;
        $identifier = (string) $invoice->getKey();

        // 1–3. What the document is, before what it points at. A cancelled invoice and an empty one fail for
        // reasons a user can act on without knowing anything about the chart of accounts.
        if ($invoice->status !== SalesInvoiceStatus::Draft) {
            throw InvoiceCannotBeIssued::notADraft($invoice->number ?? $identifier, $invoice->status);
        }

        if ($invoice->lines->isEmpty()) {
            throw InvoiceCannotBeIssued::withoutLines($identifier);
        }

        if (bccomp($invoice->total, '0', Money::SCALE) <= 0) {
            throw InvoiceCannotBeIssued::withZeroTotal($identifier, $invoice->total);
        }

        // 4. Everything the invoice points at, checked as it is now rather than as it was.
        $this->assertIssuable($invoice, $company);

        // 5–6. The calendar, before any number is reserved. `PostingService` refuses a closed period too, but
        // only after it has taken a number of its own.
        $period = $this->calendar->periodFor($company, $invoice->invoice_date->startOfDay());

        if (! $period->acceptsPostings()) {
            throw InvoiceCannotBeIssued::intoClosedPeriod($identifier, $period->label, $period->status);
        }

        // 7. Built before the transaction opens: the map writes nothing, so a refusal here costs no lock.
        $lines = $this->postingMap->for($invoice);

        return DB::transaction(function () use ($invoice, $company, $identifier, $period, $lines, $actor): SalesInvoice {
            // 8. The row lock, and the only check in this method that holds under concurrency.
            //
            // Every refusal above read `$invoice` as it stood before the transaction opened, which is what two
            // racing requests both do: both see `draft`, both pass, and the loser used to reach the unique index
            // over `journal_entries.source_id` and come back as a raw `QueryException` — a 500 to any caller,
            // for a condition the domain has a precise answer for. Locking the row and re-reading it turns the
            // loser into `invoice-not-a-draft`, the same refusal a sequential second attempt already gets.
            //
            // Taken before the document number is reserved, so the loser costs no number and no contention on
            // `document_sequences`. `cancel()` opens the same way and for the same reason.
            //
            // This does not replace the database's protection and must not be read as doing so. The unique
            // index still decides the case the application cannot see, and the immutability trigger still
            // refuses to rewind an issued invoice to draft; both are covered by their own tests. The lock is
            // what makes the ordinary race produce a readable refusal instead of a stack trace.
            $locked = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== SalesInvoiceStatus::Draft) {
                throw InvoiceCannotBeIssued::notADraft($locked->number ?? $identifier, $locked->status);
            }

            // 9. Gapless, and reserved inside the transaction — `DocumentNumberService` refuses to run outside
            // one, precisely so a rollback returns the number instead of leaving a hole.
            $number = $this->numbers->next($company, DocumentType::SalesInvoice, $period);

            // 10. `JournalVoucher` by explicit choice, not by omission — see the note above on the two counters.
            // The source document is what ties the entry back, and what stops it being posted twice.
            $entry = $this->posting->postNew($company, new JournalEntryData(
                entryDate: $invoice->invoice_date->startOfDay(),
                // Clipped to the ledger's column width. `customers.name` is as wide as
                // `journal_entries.description`, so a long trading name used to push this past the column and
                // fail the whole issue with a raw database error — see `LedgerNarration`.
                description: LedgerNarration::limit(sprintf('Invoice %s — %s', $number, $invoice->customer->name)),
                lines: $lines,
                reference: $number,
                documentType: DocumentType::JournalVoucher,
                source: SourceDocument::for($invoice),
            ), $actor);

            // 11. One save, carrying the whole issued state. Split across two writes it would momentarily be an
            // invoice that is issued with no number, and `sales_invoices_number_matches_status_check` refuses
            // exactly that — the constraint is what makes the single save mandatory rather than merely tidy.
            $invoice->status = SalesInvoiceStatus::Issued;
            $invoice->number = $number;
            $invoice->issued_at = now();
            // Who issued it, recorded on the document as well as on the entry it posted. Written here or
            // never: the immutability trigger freezes this column the moment the invoice leaves draft, so a
            // value missed now cannot be filled in afterwards. Null when the system issues without a person,
            // matching `created_by_id` and `cancelled_by_id`.
            $invoice->issued_by_id = $actor?->getKey();
            $invoice->journal_entry_id = $entry->getKey();
            $invoice->save();

            return $invoice->refresh();
        });
    }

    /**
     * Cancel an issued invoice, reversing its posting.
     *
     * The counterpart to `issue()`, and deliberately not its undo. Nothing is deleted and nothing is edited: the
     * invoice keeps its number, its dates and its figures, its original entry stays in the ledger, and a mirror
     * entry is posted alongside. An auditor sees the document, the posting and the correction — which is what
     * they expect, and what a deletion would destroy.
     *
     * WHICH PERIOD HAS TO BE OPEN
     * ---------------------------
     * The reversal's, not the invoice's. `PostingService::reverse()` dates the mirror today rather than at the
     * original's date, because backdating a correction into a closed period is precisely what closing prevents.
     * So an invoice from a closed March may still be cancelled today; what refuses a cancellation is today's
     * period being closed. Checked here as well as inside the posting service so the caller is told about the
     * *invoice* rather than about a journal entry they never asked about.
     *
     * NO INVOICE NUMBER IS CONSUMED
     * -----------------------------
     * `reverse()` copies the original entry's document type, and Stage 3 types a sales posting as
     * `JournalVoucher`. The mirror therefore draws from the journal voucher counter, and the `sales_invoice`
     * counter is untouched — so cancelling invoice 1 does not push the next invoice from 3 to 4. That is the
     * whole reason Stage 3 split the counters, and a test asserts it across a cancel-then-issue sequence.
     *
     * WHAT HOLDS UNDER CONCURRENCY
     * ----------------------------
     * The row lock, then the database. Two simultaneous cancellations both want the same row; the second waits,
     * re-reads a now-cancelled invoice, and is refused by the state check. Were that check somehow bypassed, the
     * immutability trigger refuses any update to a cancelled invoice and `asids_journal_entries_immutable`
     * refuses to re-reverse an entry. The service check is the readable answer, not the protection.
     */
    public function cancel(SalesInvoice $invoice, string $reason, ?User $actor = null): SalesInvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceCannotBeCancelled::withoutReason($invoice->number ?? (string) $invoice->getKey());
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): SalesInvoice {
            // Locked before anything is read, so a concurrent attempt queues here rather than racing to the
            // trigger. Re-read through the lock: the in-memory instance may predate another request's work.
            $locked = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing(['company', 'journalEntry']);

            $identifier = $locked->number ?? (string) $locked->getKey();

            if ($locked->status === SalesInvoiceStatus::Cancelled) {
                throw InvoiceCannotBeCancelled::alreadyCancelled($identifier);
            }

            if ($locked->status !== SalesInvoiceStatus::Issued) {
                throw InvoiceCannotBeCancelled::notIssued($identifier, $locked->status);
            }

            // Phase 4 will make this reachable. Stated now so the rule exists before the thing it guards
            // against does — a cancellation that stranded a receipt would be found by a customer, not a test.
            if (bccomp($locked->amount_paid, '0', Money::SCALE) > 0) {
                throw InvoiceCannotBeCancelled::partiallyPaid($identifier, $locked->amount_paid);
            }

            $entry = $locked->journalEntry;

            if ($entry === null) {
                throw InvoiceCannotBeCancelled::withoutJournalEntry($identifier);
            }

            // Two companies in one workspace share a `tenant_id`, so row level security is satisfied by either
            // one's entries. Only this comparison stops a reversal landing in a sibling's ledger.
            if ((string) $entry->company_id !== (string) $locked->company_id) {
                throw InvoiceCannotBeCancelled::journalEntryOutsideCompany($identifier);
            }

            // Compared to `Posted` explicitly rather than asking `isPosted()`, which answers "has been posted"
            // and is true for a reversed entry as well. Using it here would have let a second cancellation
            // through to `PostingService`, which refuses it — but with a message about a journal entry rather
            // than about this invoice.
            if ($entry->status !== JournalEntryStatus::Posted) {
                throw InvoiceCannotBeCancelled::journalEntryNotReversible(
                    $identifier,
                    $entry->number ?? (string) $entry->getKey(),
                    $entry->status->value,
                );
            }

            $reversalDate = CarbonImmutable::now()->startOfDay();
            $period = $this->calendar->periodFor($locked->company, $reversalDate);

            if (! $period->acceptsPostings()) {
                throw InvoiceCannotBeCancelled::intoClosedPeriod($identifier, $period->label, $period->status);
            }

            $this->posting->reverse($entry, $reason, $reversalDate, $actor);

            // One save, like issuing. The CHECK ties the cancellation columns to the status, so a status
            // written without them — or them without it — is refused by the database rather than merely
            // avoided here.
            $locked->status = SalesInvoiceStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->cancelled_by_id = $actor?->getKey();
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Remove a draft.
     *
     * Hard deletion, per ADR 0007 decision B2: a never-issued draft is not an accounting document — nothing
     * cites it, no return reports it, no auditor will ask about it — so retaining a tombstone would imply
     * otherwise. The lines go with it by cascade.
     *
     * Refused for anything else. An issued invoice is a statutory record; Milestone 5 owns what may be done to
     * one, and the answer will not be deletion.
     */
    public function deleteDraft(SalesInvoice $invoice): void
    {
        $this->assertEditable($invoice);

        DB::transaction(static function () use ($invoice): void {
            $invoice->delete();
        });
    }

    /**
     * Rebuild every line and every total.
     *
     * The heart of the service, and the one place invoice arithmetic happens. Split into stages that mirror
     * `InvoiceTotalsCalculator`'s documented order, because tax must be charged on what the customer actually
     * pays: computing it before the discounts would overstate the liability with every figure on the document
     * internally consistent.
     *
     * @param  list<SalesInvoiceLineData>  $lines
     * @param  numeric-string|null  $headerDiscount
     */
    private function replaceLines(SalesInvoice $invoice, Company $company, array $lines, ?string $headerDiscount): void
    {
        if ($lines === []) {
            throw BusinessRuleViolation::make(
                'invoice-without-lines',
                'An invoice needs at least one line. Nothing is being charged for otherwise.',
            );
        }

        $currency = $invoice->currency_code;
        $precision = $company->currency_precision;

        // Stage 1: gross, own discount, net — per line, before anything at header level.
        $prepared = [];
        $nets = [];

        foreach ($lines as $position => $line) {
            $account = $this->resolveRevenueAccount($company, $line->revenueAccountId);
            $quantity = $this->assertDecimal($line->quantity, 'quantity');

            if (bccomp($quantity, '0', Money::SCALE) === 0) {
                throw BusinessRuleViolation::make(
                    'invoice-line-zero-quantity',
                    sprintf('Line %d has a quantity of zero, so it charges for nothing.', $position + 1),
                );
            }

            $unitPrice = Money::of($this->assertDecimal($line->unitPrice, 'unit price'), $currency);
            $gross = $this->totals->lineGross($unitPrice, $quantity);
            $discount = $this->totals->lineDiscount($gross, $line->discountPercent, $line->discountAmount);
            $net = $gross->minus($discount);

            $prepared[] = [
                'data' => $line,
                'account' => $account,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'lineDiscount' => $discount,
                'net' => $net,
            ];
            $nets[] = $net;
        }

        // Stage 2: the header discount, spread across the line nets in proportion to them.
        $headerShares = $this->headerShares($headerDiscount, $nets, $currency);

        /*
         * Stage 3: tax per line, and the running totals — all in memory, nothing written yet.
         *
         * Computing before writing is what lets the invoice be saved exactly once, with figures that already
         * agree with its lines. Saving first and correcting afterwards produced a trail reading "created at
         * zero, immediately changed" for every invoice ever raised, and left the row briefly disagreeing with
         * itself.
         */
        $rows = [];
        $subtotal = Money::zero($currency);
        $taxTotal = Money::zero($currency);
        $discountTotal = Money::zero($currency);

        foreach ($prepared as $index => $entry) {
            /** @var SalesInvoiceLineData $data */
            $data = $entry['data'];
            /** @var Money $net */
            $net = $entry['net'];
            /** @var Money $lineDiscount */
            $lineDiscount = $entry['lineDiscount'];

            $lineSubtotal = $net->minus($headerShares[$index]);

            [$taxCodeId, $taxRate] = $this->resolveTax($company, $data->taxCode, $invoice->invoice_date);
            $tax = $this->totals->taxOnLine($lineSubtotal, $taxRate, $precision);

            $rows[] = [
                'line_number' => $index + 1,
                'description' => $data->description,
                'quantity' => $entry['quantity'],
                'unit_price' => $this->decimal($entry['unitPrice']),
                'discount_percent' => $data->discountPercent,
                'discount_amount' => $data->discountAmount,
                'line_subtotal' => $this->decimal($lineSubtotal),
                'tax_code_id' => $taxCodeId,
                'tax_rate' => $taxRate,
                'tax_amount' => $this->decimal($tax),
                'line_total' => $this->decimal($lineSubtotal->plus($tax)),
                'revenue_account_id' => (string) $entry['account']->getKey(),
                'branch_id' => $this->resolveBranchId($company, $data->branchId),
            ];

            $subtotal = $subtotal->plus($lineSubtotal);
            $taxTotal = $taxTotal->plus($tax);
            $discountTotal = $discountTotal->plus($lineDiscount)->plus($headerShares[$index]);
        }

        $total = $subtotal->plus($taxTotal);

        if ($total->isNegative()) {
            // A negative invoice is a credit note — its own document, with its own numbering and posting.
            // Raised before anything is written, so a rejected invoice leaves nothing behind.
            throw BusinessRuleViolation::make(
                'invoice-total-negative',
                'The invoice total is negative. A negative document is a credit note, not an invoice with a '
                .'minus sign.',
            );
        }

        // Stage 4: one save, carrying figures that already match the lines about to be written.
        $invoice->subtotal = $this->decimal($subtotal);
        $invoice->discount_total = $this->decimal($discountTotal);
        $invoice->tax_total = $this->decimal($taxTotal);
        $invoice->total = $this->decimal($total);
        // Zero until the payments phase, held there by a phase-scoped CHECK. `amount_due` follows from the
        // invariant the database also asserts.
        $invoice->amount_paid = '0.0000';
        $invoice->amount_due = $this->decimal($total);
        $invoice->save();

        // Stage 5: replace the lines wholesale.
        $invoice->lines()->delete();

        foreach ($rows as $row) {
            $model = new SalesInvoiceLine;
            $model->company_id = $company->getKey();
            $model->sales_invoice_id = $invoice->getKey();

            foreach ($row as $column => $value) {
                $model->setAttribute($column, $value);
            }

            $model->save();
        }
    }

    /**
     * One header-discount share per line, zero when there is no header discount.
     *
     * @param  numeric-string|null  $headerDiscount
     * @param  list<Money>  $nets
     * @return list<Money>
     */
    private function headerShares(?string $headerDiscount, array $nets, string $currency): array
    {
        if ($headerDiscount === null || $headerDiscount === '') {
            return array_map(static fn (): Money => Money::zero($currency), $nets);
        }

        $discount = Money::of($this->assertDecimal($headerDiscount, 'discount'), $currency);

        if ($discount->isNegative()) {
            throw InvalidInvoiceDiscount::negativeAmount();
        }

        if ($discount->isZero()) {
            return array_map(static fn (): Money => Money::zero($currency), $nets);
        }

        $lineTotal = array_reduce(
            $nets,
            static fn (Money $carry, Money $net): Money => $carry->plus($net),
            Money::zero($currency),
        );

        if ($discount->isGreaterThan($lineTotal)) {
            throw InvalidInvoiceDiscount::exceedsInvoice();
        }

        return $this->totals->allocateHeaderDiscount($discount, $nets);
    }

    /**
     * The tax code id and the rate to snapshot, for a line naming a code.
     *
     * Resolution is by code and date. The rate is copied onto the line so the invoice still reads the rate it
     * was charged after the code's rate changes — ADR 0006 made a rate change a new row precisely so history
     * survives, and re-resolving on every read would defeat it.
     *
     * @return array{0: string|null, 1: numeric-string}
     */
    private function resolveTax(Company $company, ?string $code, CarbonImmutable $invoiceDate): array
    {
        if ($code === null) {
            return [null, '0.0000'];
        }

        $taxCode = $this->resolver->resolve($company, $code, $invoiceDate);

        return [(string) $taxCode->getKey(), $taxCode->rate];
    }

    /**
     * The customer, provided it belongs to this company and may be invoiced.
     */
    private function resolveCustomer(Company $company, string $customerId, bool $forNewInvoice): Customer
    {
        $customer = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw BusinessRuleViolation::make(
                'customer-outside-company',
                'That customer belongs to a different company, or does not exist.',
            );
        }

        if ($forNewInvoice && ! $customer->acceptsNewInvoices()) {
            // Existing invoices are unaffected by a dormant or archived customer — what is already owed is
            // still owed. Only a new one is refused.
            throw BusinessRuleViolation::make(
                'customer-not-invoiceable',
                sprintf(
                    'Customer %s is %s and cannot be invoiced. Reactivate it first.',
                    $customer->code,
                    strtolower($customer->status->label()),
                ),
            );
        }

        return $customer;
    }

    /**
     * The revenue account a line credits.
     *
     * Must be income, postable, and belong to this company. The type check is the one the database cannot make:
     * a CHECK cannot join to `accounts`. Point a sales line at an expense account and the invoice still
     * balances while the profit and loss account is wrong in two places at once.
     */
    private function resolveRevenueAccount(Company $company, string $accountId): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw BusinessRuleViolation::make(
                'revenue-account-outside-company',
                'That revenue account belongs to a different company, or does not exist.',
            );
        }

        if ($account->type !== AccountType::Income) {
            throw BusinessRuleViolation::make(
                'revenue-account-wrong-type',
                sprintf(
                    'Account %s is %s. An invoice line credits revenue, so it has to be an income account.',
                    $account->code,
                    $account->type->value,
                ),
            );
        }

        if (! $account->acceptsPostings()) {
            throw BusinessRuleViolation::make(
                'revenue-account-not-postable',
                sprintf('Account %s does not accept postings, so an invoice line cannot use it.', $account->code),
            );
        }

        return $account;
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
                'branch-outside-company',
                'That branch belongs to a different company.',
            );
        }

        return $branchId;
    }

    /**
     * Everything the invoice points at, re-checked as it stands now.
     *
     * The B5 contract, minus the accounts — and the omission is deliberate rather than an oversight.
     * `InvoicePostingMap` already validates every account it touches: company ownership, postability, and the
     * type rules for receivable, revenue and tax output. Repeating those here would put the same rule in two
     * places, and two copies of a rule drift. The map runs before the transaction opens, so its refusals are
     * exactly as free as these — nothing is reserved either way.
     *
     * What is left is what the map has no reason to look at: the customer, the tax codes themselves as opposed
     * to the accounts they name, and the branch.
     */
    private function assertIssuable(SalesInvoice $invoice, Company $company): void
    {
        // Archived or dormant since the draft was written. Issuing is what creates the receivable, so it is a
        // new invoice in every sense that matters — `CustomerService::archive()` refuses a customer with a
        // balance, and letting a draft issue afterwards would create the balance it was archived for not having.
        $this->resolveCustomer($company, (string) $invoice->customer_id, forNewInvoice: true);

        // The branch may have been reassigned or removed. The posting map copies it onto every journal line, so
        // a branch belonging elsewhere would tag this company's ledger with another's dimension.
        $this->resolveBranchId($company, $invoice->branch_id);

        foreach ($invoice->lines as $line) {
            if ($line->tax_code_id === null) {
                continue;
            }

            // The map checks the *output account* belongs to this company; nothing checks the code does. Two
            // companies in one workspace share a `tenant_id`, so row level security is satisfied by either.
            $belongs = TaxCode::query()
                ->forCompany((string) $company->getKey())
                ->whereKey($line->tax_code_id)
                ->exists();

            if (! $belongs) {
                throw BusinessRuleViolation::make(
                    'tax-code-outside-company',
                    sprintf(
                        'Line %d names a tax code belonging to a different company, or one that no longer '
                        .'exists. The invoice cannot be issued until the line is corrected.',
                        $line->line_number,
                    ),
                    ['line' => $line->line_number],
                );
            }
        }
    }

    private function assertEditable(SalesInvoice $invoice): void
    {
        if (! $invoice->isEditable()) {
            throw BusinessRuleViolation::make(
                'invoice-not-editable',
                sprintf(
                    'Invoice %s is %s and can no longer be changed. Correct it with a credit note or a '
                    .'cancellation instead.',
                    $invoice->number ?? $invoice->getKey(),
                    strtolower($invoice->status->label()),
                ),
            );
        }
    }

    private function assertDates(CarbonImmutable $invoiceDate, CarbonImmutable $dueDate): void
    {
        if ($dueDate->lessThan($invoiceDate)) {
            throw BusinessRuleViolation::make(
                'due-date-before-invoice-date',
                sprintf(
                    'A due date of %s is before the invoice date of %s, which would make the invoice overdue '
                    .'the moment it was issued.',
                    $dueDate->toDateString(),
                    $invoiceDate->toDateString(),
                ),
            );
        }
    }

    /**
     * A `Money` as a decimal string the type checker accepts as numeric.
     *
     * `Money::toDecimalString()` is deliberately typed as a plain `string`: its own docblock explains that
     * PHPStan cannot see numeric-ness through `sprintf`, and that a cast or an assertion would claim the
     * property rather than establish it. Passing the result through `bcadd` at the ledger's scale establishes
     * it by doing arithmetic — a numeric no-op that returns `numeric-string` by the function's own signature.
     *
     * `assertDecimal()` does it with a real `is_numeric()` check rather than a cast. The check is not ceremony:
     * it is the same boundary guard applied to every other decimal reaching this service, and it *proves*
     * numeric-ness to the type checker where `Money` could only have claimed it.
     *
     * @return numeric-string
     */
    private function decimal(Money $amount): string
    {
        return $this->assertDecimal($amount->toDecimalString(), 'amount');
    }

    /**
     * @return numeric-string
     */
    private function assertDecimal(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            throw BusinessRuleViolation::make(
                'invoice-value-not-a-number',
                sprintf('"%s" is not a number, so it cannot be a %s.', $value, $field),
            );
        }

        return $trimmed;
    }

    /**
     * The header discount already on an invoice, recovered from what the lines carry.
     *
     * `sales_invoices.discount_total` mixes line and header discounts, so it cannot be read back directly.
     * Recomputing from the difference between each line's gross-less-own-discount and its stored subtotal is
     * exact, because that difference *is* the allocated share.
     *
     * @return numeric-string|null
     */
    private function existingHeaderDiscount(SalesInvoice $invoice): ?string
    {
        $currency = $invoice->currency_code;
        $allocated = Money::zero($currency);

        $invoice->loadMissing('lines');

        foreach ($invoice->lines as $line) {
            $gross = $this->totals->lineGross(Money::of($line->unit_price, $currency), $line->quantity);
            $own = $this->totals->lineDiscount($gross, $line->discount_percent, $line->discount_amount);
            $allocated = $allocated->plus($gross->minus($own)->minus($line->subtotalMoney($currency)));
        }

        return $allocated->isZero() ? null : $this->decimal($allocated);
    }

    /**
     * @return list<SalesInvoiceLineData>
     */
    private function lineDataFrom(mixed $lines): array
    {
        if (! is_array($lines)) {
            throw BusinessRuleViolation::make(
                'invoice-lines-not-a-list',
                'The lines must be supplied as a list.',
            );
        }

        return array_map(
            static fn (mixed $line): SalesInvoiceLineData => $line instanceof SalesInvoiceLineData
                ? $line
                : SalesInvoiceLineData::fromArray((array) $line),
            array_values($lines),
        );
    }

    /**
     * The invoice's current lines as submission data, so an update that does not touch them still recomputes
     * correctly against a changed date or customer.
     *
     * @return list<SalesInvoiceLineData>
     */
    private function lineDataFromExisting(SalesInvoice $invoice): array
    {
        // Eager-loaded explicitly. `Model::shouldBeStrict()` forbids lazy loading outside production, and
        // reading `$line->taxCode` per line would be an N+1 in production and a hard failure everywhere else —
        // which is exactly what strict mode is for.
        $invoice->loadMissing('lines.taxCode');

        return array_values($invoice->lines
            ->map(fn (SalesInvoiceLine $line): SalesInvoiceLineData => new SalesInvoiceLineData(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
                revenueAccountId: $line->revenue_account_id,
                // By code, not id: the invoice date may have moved, and the code has to re-resolve against it.
                taxCode: $line->taxCode?->code,
                discountPercent: $line->discount_percent,
                discountAmount: $line->discount_amount,
                branchId: $line->branch_id,
            ))
            ->all());
    }
}
