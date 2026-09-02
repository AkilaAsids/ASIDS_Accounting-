<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\Services;

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
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Exceptions\BillCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\BillLine;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Sales\Application\Services\InvoiceTotalsCalculator;
use Asids\Core\Sales\Application\Services\LedgerNarration;
use Asids\Core\Sales\Application\Services\TaxRateResolver;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Draft bills, and their posting to the ledger — the payable-side mirror of `SalesInvoiceService`.
 *
 * A bill is a *received* document, so where an invoice is *issued* a bill is *posted*. The lifecycle is
 * otherwise identical: a draft is written, changed and deleted freely; posting turns it into a payable, gives
 * it an internal `BILL-` number and an entry in a ledger that will never let it be edited again.
 *
 * The arithmetic — line gross, discount, tax on the net — is currency-agnostic and identical to the sales side,
 * so `TaxRateResolver` and `InvoiceTotalsCalculator` are reused unchanged. Only the posting map differs, and it
 * differs only by swapping debits and credits.
 *
 * Three things are worth naming before the code, mirroring the sales service, plus one that has no analogue.
 *
 * **Lines are replaced wholesale, never diffed.** "These are its lines now" is what a user means when they save.
 *
 * **Tax is resolved by code and date, never by id.** The line names `VAT`; the resolver decides which
 * effective-dated row that means for this bill's date.
 *
 * **Cross-company validation is not redundant with row level security.** Two companies in one workspace share a
 * `tenant_id`, so the policy is satisfied by either one's suppliers, accounts and tax codes.
 *
 * **The duplicate supplier-invoice-number guard has no sales analogue.** A supplier assigns its own invoice
 * number, and recording the same one twice is the classic accounts-payable route to paying a bill twice
 * (Gate-1 dec. 5). Refused per supplier per company, trimmed and matched exactly — the pre-check names it, and
 * the unique index is the authority under concurrency.
 */
final readonly class BillService
{
    public function __construct(
        private TaxRateResolver $resolver,
        private InvoiceTotalsCalculator $totals,
        private BillPostingMap $postingMap,
        private PostingService $posting,
        private DocumentNumberService $numbers,
        private FiscalCalendarService $calendar,
    ) {}

    public function createDraft(Company $company, BillData $data, ?string $createdById = null): Bill
    {
        $supplierNumber = $this->assertSupplierNumberPresent($data->supplierInvoiceNumber);

        $supplier = $this->resolveSupplier($company, $data->supplierId, forNewBill: true);
        $dueDate = $data->dueDate ?? $supplier->dueDateFor($data->billDate);

        $this->assertDates($data->billDate, $dueDate);
        $this->assertNoDuplicateSupplierNumber($company, $supplier, $supplierNumber, null);

        return DB::transaction(function () use ($company, $data, $supplier, $dueDate, $supplierNumber, $createdById): Bill {
            $bill = new Bill;

            $bill->company_id = $company->getKey();
            $bill->supplier_id = $supplier->getKey();
            $bill->branch_id = $this->resolveBranchId($company, $data->branchId);
            $bill->supplier_invoice_number = $supplierNumber;
            $bill->bill_date = $data->billDate;
            $bill->due_date = $dueDate;
            $bill->currency_code = $company->base_currency_code;
            $bill->notes = $data->notes;
            $bill->terms = $data->terms;

            // Set explicitly rather than left to the column defaults, so an unsaved instance reads back the
            // same as a saved one under `Model::shouldBeStrict()` — the trap Phase 1 hit on
            // `must_change_password` and Phase 2 hit again on `is_closed`.
            $bill->status = BillStatus::Draft;
            $bill->exchange_rate = null;
            $bill->number = null;
            $bill->posted_at = null;
            $bill->posted_by_id = null;
            $bill->journal_entry_id = null;
            $bill->created_by_id = $createdById;

            $this->replaceLinesGuardingDuplicate($bill, $company, $data->lines, $data->discountAmount, $supplier);

            return $bill->refresh();
        });
    }

    /**
     * Change a draft.
     *
     * Takes an array rather than a DTO, so `array_key_exists()` can distinguish "leave this alone" from "set
     * this to null" — the distinction a whole-DTO signature cannot express, and one that matters for the header
     * discount, the branch, notes and terms.
     *
     * Recognised keys: `supplier_id`, `bill_date`, `due_date`, `supplier_invoice_number`, `branch_id`,
     * `discount_amount`, `notes`, `terms`, `lines`. Anything else is ignored rather than rejected.
     *
     * Supplying `lines` replaces every line. Omitting it leaves them and recomputes the totals anyway — a
     * changed `bill_date` can change which tax rate applies even when no line moved. A changed
     * `supplier_invoice_number` re-runs the duplicate check.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(Bill $bill, array $attributes): Bill
    {
        $this->assertEditable($bill);

        $company = $bill->company;

        $supplier = array_key_exists('supplier_id', $attributes)
            ? $this->resolveSupplier($company, (string) $attributes['supplier_id'], forNewBill: true)
            : $bill->supplier;

        $billDate = array_key_exists('bill_date', $attributes)
            ? CarbonImmutable::parse((string) $attributes['bill_date'])->startOfDay()
            : $bill->bill_date->startOfDay();

        // An explicitly supplied null re-derives from the supplier's terms rather than clearing the column,
        // which is not nullable. "Use the default" is the only sensible reading of a cleared due date.
        $dueDate = array_key_exists('due_date', $attributes)
            ? ($attributes['due_date'] === null
                ? $supplier->dueDateFor($billDate)
                : CarbonImmutable::parse((string) $attributes['due_date'])->startOfDay())
            : $bill->due_date->startOfDay();

        $this->assertDates($billDate, $dueDate);

        $supplierNumber = array_key_exists('supplier_invoice_number', $attributes)
            ? $this->assertSupplierNumberPresent((string) $attributes['supplier_invoice_number'])
            : $bill->supplier_invoice_number;

        $this->assertNoDuplicateSupplierNumber($company, $supplier, $supplierNumber, (string) $bill->getKey());

        return DB::transaction(function () use ($bill, $company, $supplier, $billDate, $dueDate, $supplierNumber, $attributes): Bill {
            $bill->fill(array_intersect_key($attributes, array_flip(['notes', 'terms'])));

            $bill->supplier_id = $supplier->getKey();
            $bill->bill_date = $billDate;
            $bill->due_date = $dueDate;
            $bill->supplier_invoice_number = $supplierNumber;

            if (array_key_exists('branch_id', $attributes)) {
                $bill->branch_id = $attributes['branch_id'] === null
                    ? null
                    : $this->resolveBranchId($company, (string) $attributes['branch_id']);
            }

            /** @var numeric-string|null $discount */
            $discount = array_key_exists('discount_amount', $attributes)
                ? ($attributes['discount_amount'] === null ? null : trim((string) $attributes['discount_amount']))
                : $this->existingHeaderDiscount($bill);

            $lines = array_key_exists('lines', $attributes)
                ? $this->lineDataFrom($attributes['lines'])
                : $this->lineDataFromExisting($bill);

            $this->replaceLinesGuardingDuplicate($bill, $company, $lines, $discount, $supplier);

            return $bill->refresh();
        });
    }

    /**
     * Turn a draft bill into a posted payable, recorded in the ledger.
     *
     * Everything that can refuse runs before anything is reserved — mirror `SalesInvoiceService::issue()`, with
     * the posting map's debits and credits swapped and the *lifecycle* identical.
     *
     * WHY EVERYTHING IS RE-VALIDATED
     * ------------------------------
     * A draft written in March and posted in June has had three months in which its supplier could be archived,
     * its expense account reclassified, its tax code's input account cleared, or its period closed. Posting is
     * the moment the payable becomes real, so it is a new bill in every sense that matters, and each account,
     * the supplier and the calendar are checked again.
     *
     * TWO NUMBERS, TWO SEQUENCES
     * --------------------------
     * The bill takes `BILL-…` from the `Bill` counter (non-gapless — a bill is received, not issued). Its
     * journal entry takes `JV-…` from the journal voucher counter, because a single counter feeding both would
     * hand the bill 0001 and its own entry 0002, gapping bill numbers on every post. The entry is typed
     * `JournalVoucher` by explicit choice, and the unique index over `source_id` is what makes a second posting
     * impossible.
     *
     * NOTHING IS RECOMPUTED
     * ---------------------
     * The money was rounded to the currency when the draft was written; the posting map sums the stored values,
     * so the entry balances by construction. Re-resolving a rate here would silently reprice an agreed document.
     */
    public function post(Bill $bill, ?User $actor = null): Bill
    {
        $bill->loadMissing(['company', 'supplier', 'lines.taxCode']);

        $company = $bill->company;
        $identifier = (string) $bill->getKey();

        // 1–3. What the document is, before what it points at.
        if ($bill->status !== BillStatus::Draft) {
            throw BillCannotBePosted::notADraft($bill->number ?? $identifier, $bill->status);
        }

        if ($bill->lines->isEmpty()) {
            throw BillCannotBePosted::withoutLines();
        }

        if (bccomp($bill->total, '0', Money::SCALE) <= 0) {
            throw BillCannotBePosted::withZeroTotal($identifier, $bill->total);
        }

        // 4. Everything the bill points at, checked as it is now rather than as it was.
        $this->assertPostable($bill, $company);

        // 5–6. The calendar, before any number is reserved.
        $period = $this->calendar->periodFor($company, $bill->bill_date->startOfDay());

        if (! $period->acceptsPostings()) {
            throw BillCannotBePosted::intoClosedPeriod($identifier, $period->label, $period->status);
        }

        // 7. Built before the transaction opens: the map writes nothing, so a refusal here costs no lock.
        $lines = $this->postingMap->for($bill);

        return DB::transaction(function () use ($bill, $company, $identifier, $period, $lines, $actor): Bill {
            // 8. The row lock, and the only check in this method that holds under concurrency. Two racing
            // requests both see `draft` before the transaction; the loser re-reads a posted bill here and is
            // refused readably rather than reaching the unique index over `journal_entries.source_id` as a 500.
            $locked = Bill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== BillStatus::Draft) {
                throw BillCannotBePosted::notADraft($locked->number ?? $identifier, $locked->status);
            }

            // 9. The internal bill number — non-gapless, but still drawn inside the transaction so a rollback
            // returns it rather than leaving a hole in practice.
            $number = $this->numbers->next($company, DocumentType::Bill, $period);

            // 10. The ledger entry, typed `JournalVoucher` by explicit choice — the two-series decision. The
            // source document ties the entry back and is what stops it being posted twice.
            $entry = $this->posting->postNew($company, new JournalEntryData(
                entryDate: $bill->bill_date->startOfDay(),
                description: LedgerNarration::limit(sprintf('Bill %s — %s', $number, $bill->supplier->name)),
                lines: $lines,
                reference: $number,
                documentType: DocumentType::JournalVoucher,
                source: SourceDocument::for($bill),
            ), $actor);

            // 11. One save, carrying the whole posted state. Split across two writes it would momentarily be a
            // posted bill with no number, which `bills_number_matches_status_check` refuses.
            $bill->status = BillStatus::Posted;
            $bill->number = $number;
            $bill->posted_at = now();
            $bill->posted_by_id = $actor?->getKey();
            $bill->journal_entry_id = $entry->getKey();
            $bill->save();

            return $bill->refresh();
        });
    }

    /**
     * Remove a draft. Hard deletion — a never-posted draft is not an accounting document. The lines cascade.
     *
     * Refused for anything else: a posted bill is a statutory record.
     */
    public function deleteDraft(Bill $bill): void
    {
        $this->assertEditable($bill);

        DB::transaction(static function () use ($bill): void {
            $bill->delete();
        });
    }

    /**
     * `replaceLines()`, turning the duplicate-number race into the same named refusal the pre-check produces.
     *
     * The pre-check is read-then-write: two concurrent drafts for the same supplier and number can both pass it,
     * and only one insert survives. Left to the database that surfaces as a raw constraint violation; caught
     * here it becomes the same `bill-duplicate-supplier-number` refusal. The index stays the authority.
     *
     * @param  list<BillLineData>  $lines
     * @param  numeric-string|null  $headerDiscount
     */
    private function replaceLinesGuardingDuplicate(Bill $bill, Company $company, array $lines, ?string $headerDiscount, Supplier $supplier): void
    {
        try {
            $this->replaceLines($bill, $company, $lines, $headerDiscount);
        } catch (QueryException $exception) {
            if ($this->isDuplicateSupplierNumberViolation($exception)) {
                throw $this->duplicateSupplierNumber($supplier, $bill->supplier_invoice_number);
            }

            throw $exception;
        }
    }

    /**
     * Rebuild every line and every total. The one place bill arithmetic happens, identical to the sales side.
     *
     * @param  list<BillLineData>  $lines
     * @param  numeric-string|null  $headerDiscount
     */
    private function replaceLines(Bill $bill, Company $company, array $lines, ?string $headerDiscount): void
    {
        if ($lines === []) {
            throw BusinessRuleViolation::make(
                'bill-has-no-lines',
                'A bill needs at least one line. Nothing is being recorded otherwise.',
            );
        }

        $currency = $bill->currency_code;
        $precision = $company->currency_precision;

        // Stage 1: gross, own discount, net — per line, before anything at header level.
        $prepared = [];
        $nets = [];

        foreach ($lines as $position => $line) {
            $account = $this->resolveExpenseAccount($company, $line->expenseAccountId);
            $quantity = $this->assertDecimal($line->quantity, 'quantity');

            if (bccomp($quantity, '0', Money::SCALE) === 0) {
                throw BusinessRuleViolation::make(
                    'bill-line-zero-quantity',
                    sprintf('Line %d has a quantity of zero, so it records nothing.', $position + 1),
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

        // Stage 3: tax per line, and the running totals — all in memory, nothing written yet.
        $rows = [];
        $subtotal = Money::zero($currency);
        $taxTotal = Money::zero($currency);
        $discountTotal = Money::zero($currency);

        foreach ($prepared as $index => $entry) {
            /** @var BillLineData $data */
            $data = $entry['data'];
            /** @var Money $net */
            $net = $entry['net'];
            /** @var Money $lineDiscount */
            $lineDiscount = $entry['lineDiscount'];

            $lineSubtotal = $net->minus($headerShares[$index]);

            [$taxCodeId, $taxRate] = $this->resolveTax($company, $data->taxCode, $bill->bill_date);
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
                'expense_account_id' => (string) $entry['account']->getKey(),
                'branch_id' => $this->resolveBranchId($company, $data->branchId),
            ];

            $subtotal = $subtotal->plus($lineSubtotal);
            $taxTotal = $taxTotal->plus($tax);
            $discountTotal = $discountTotal->plus($lineDiscount)->plus($headerShares[$index]);
        }

        $total = $subtotal->plus($taxTotal);

        if ($total->isNegative()) {
            // A negative bill is a debit note — its own document. Raised before anything is written.
            throw BusinessRuleViolation::make(
                'bill-total-negative',
                'The bill total is negative. A negative document is a debit note, not a bill with a minus sign.',
            );
        }

        // Stage 4: one save, carrying figures that already match the lines about to be written.
        $bill->subtotal = $this->decimal($subtotal);
        $bill->discount_total = $this->decimal($discountTotal);
        $bill->tax_total = $this->decimal($taxTotal);
        $bill->total = $this->decimal($total);
        // Zero until the payments phase, held there by a phase-scoped CHECK. `amount_due` follows the invariant
        // the database also asserts.
        $bill->amount_paid = '0.0000';
        $bill->amount_due = $this->decimal($total);
        $bill->save();

        // Stage 5: replace the lines wholesale.
        $bill->lines()->delete();

        foreach ($rows as $row) {
            $model = new BillLine;
            $model->company_id = $company->getKey();
            $model->bill_id = $bill->getKey();

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
            throw BusinessRuleViolation::make(
                'bill-discount-negative',
                'A header discount cannot be negative.',
            );
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
            throw BusinessRuleViolation::make(
                'bill-discount-exceeds',
                'A header discount cannot be larger than the bill it is applied to.',
            );
        }

        return $this->totals->allocateHeaderDiscount($discount, $nets);
    }

    /**
     * The tax code id and the rate to snapshot, for a line naming a code.
     *
     * @return array{0: string|null, 1: numeric-string}
     */
    private function resolveTax(Company $company, ?string $code, CarbonImmutable $billDate): array
    {
        if ($code === null) {
            return [null, '0.0000'];
        }

        $taxCode = $this->resolver->resolve($company, $code, $billDate);

        return [(string) $taxCode->getKey(), $taxCode->rate];
    }

    /**
     * The supplier, provided it belongs to this company and may be billed.
     */
    private function resolveSupplier(Company $company, string $supplierId, bool $forNewBill): Supplier
    {
        $supplier = Supplier::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($supplierId)
            ->first();

        if ($supplier === null) {
            throw BusinessRuleViolation::make(
                'supplier-outside-company',
                'That supplier belongs to a different company, or does not exist.',
            );
        }

        if ($forNewBill && ! $supplier->acceptsNewBills()) {
            // Existing bills are unaffected by a dormant or archived supplier — what is already owed is still
            // owed. Only a new one is refused.
            throw BusinessRuleViolation::make(
                'supplier-not-billable',
                sprintf(
                    'Supplier %s is %s and cannot be billed. Reactivate it first.',
                    $supplier->code,
                    strtolower($supplier->status->label()),
                ),
            );
        }

        return $supplier;
    }

    /**
     * The expense account a line debits.
     *
     * Must be an expense, postable, and belong to this company. The type check is the one the database cannot
     * make: a CHECK cannot join to `accounts`. Point a bill line at an income account and the bill still
     * balances while the profit and loss account is wrong in two places at once.
     */
    private function resolveExpenseAccount(Company $company, string $accountId): Account
    {
        $account = Account::query()
            ->forCompany((string) $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw BusinessRuleViolation::make(
                'expense-account-outside-company',
                'That expense account belongs to a different company, or does not exist.',
            );
        }

        if ($account->type !== AccountType::Expense) {
            throw BusinessRuleViolation::make(
                'expense-account-wrong-type',
                sprintf(
                    'Account %s is %s. A bill line debits expense, so it has to be an expense account.',
                    $account->code,
                    $account->type->value,
                ),
            );
        }

        if (! $account->acceptsPostings()) {
            throw BusinessRuleViolation::make(
                'expense-account-not-postable',
                sprintf('Account %s does not accept postings, so a bill line cannot use it.', $account->code),
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
     * Everything the bill points at, re-checked as it stands now.
     *
     * The accounts are omitted deliberately: `BillPostingMap` validates every account it touches (company
     * ownership, postability, and the type rules for expense, input tax and payable), and it runs before the
     * transaction opens, so its refusals are exactly as free as these. What is left is what the map has no
     * reason to look at — the supplier, the tax codes themselves, and the branch.
     */
    private function assertPostable(Bill $bill, Company $company): void
    {
        // Archived or dormant since the draft was written. Posting is what creates the payable, so it is a new
        // bill in every sense that matters.
        $this->resolveSupplier($company, (string) $bill->supplier_id, forNewBill: true);

        $this->resolveBranchId($company, $bill->branch_id);

        foreach ($bill->lines as $line) {
            if ($line->tax_code_id === null) {
                continue;
            }

            // The map checks the *input account* belongs to this company; nothing checks the code does. Two
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
                        .'exists. The bill cannot be posted until the line is corrected.',
                        $line->line_number,
                    ),
                    ['line' => $line->line_number],
                );
            }
        }
    }

    private function assertEditable(Bill $bill): void
    {
        if (! $bill->isEditable()) {
            throw BusinessRuleViolation::make(
                'bill-not-editable',
                sprintf(
                    'Bill %s is %s and can no longer be changed. Correct it with a debit note or a cancellation '
                    .'instead.',
                    $bill->number ?? $bill->getKey(),
                    strtolower($bill->status->label()),
                ),
            );
        }
    }

    private function assertDates(CarbonImmutable $billDate, CarbonImmutable $dueDate): void
    {
        if ($dueDate->lessThan($billDate)) {
            throw BusinessRuleViolation::make(
                'due-date-before-bill-date',
                sprintf(
                    'A due date of %s is before the bill date of %s, which would make the bill overdue the '
                    .'moment it was recorded.',
                    $dueDate->toDateString(),
                    $billDate->toDateString(),
                ),
            );
        }
    }

    /**
     * The supplier's own invoice number, trimmed — required, and never blank.
     *
     * @return string the trimmed number
     */
    private function assertSupplierNumberPresent(string $number): string
    {
        $trimmed = trim($number);

        if ($trimmed === '') {
            throw BusinessRuleViolation::make(
                'bill-supplier-number-required',
                'A bill needs the supplier’s own invoice number. It is the statutory identity of the document '
                .'and the guard against recording the same bill twice.',
            );
        }

        return $trimmed;
    }

    /**
     * Refuse a supplier-invoice-number already recorded for this supplier in this company.
     *
     * The accounts-payable double-payment control (Gate-1 dec. 5). Trimmed and matched exactly — a supplier's
     * "INV/001" and "inv/001" are not safely the same document, unlike our own generated codes.
     */
    private function assertNoDuplicateSupplierNumber(Company $company, Supplier $supplier, string $number, ?string $ignoreId): void
    {
        $exists = Bill::query()
            ->forCompany((string) $company->getKey())
            ->where('supplier_id', $supplier->getKey())
            ->where('supplier_invoice_number', $number)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw $this->duplicateSupplierNumber($supplier, $number);
        }
    }

    private function duplicateSupplierNumber(Supplier $supplier, string $number): BusinessRuleViolation
    {
        return BusinessRuleViolation::make(
            'bill-duplicate-supplier-number',
            sprintf(
                'Supplier %s already has a bill recorded under invoice number %s. Recording it again risks '
                .'paying it twice.',
                $supplier->code,
                $number,
            ),
            ['supplier' => $supplier->code, 'supplier_invoice_number' => $number],
        );
    }

    /**
     * Whether this failure is the supplier-invoice-number uniqueness race the pre-check exists to catch.
     */
    private function isDuplicateSupplierNumberViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            && str_contains($exception->getMessage(), 'bills_company_supplier_invoice_number_unique');
    }

    /**
     * A `Money` as a decimal string the type checker accepts as numeric.
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
                'bill-value-not-a-number',
                sprintf('"%s" is not a number, so it cannot be a %s.', $value, $field),
            );
        }

        return $trimmed;
    }

    /**
     * The header discount already on a bill, recovered from what the lines carry.
     *
     * @return numeric-string|null
     */
    private function existingHeaderDiscount(Bill $bill): ?string
    {
        $currency = $bill->currency_code;
        $allocated = Money::zero($currency);

        $bill->loadMissing('lines');

        foreach ($bill->lines as $line) {
            $gross = $this->totals->lineGross(Money::of($line->unit_price, $currency), $line->quantity);
            $own = $this->totals->lineDiscount($gross, $line->discount_percent, $line->discount_amount);
            $allocated = $allocated->plus($gross->minus($own)->minus($line->subtotalMoney($currency)));
        }

        return $allocated->isZero() ? null : $this->decimal($allocated);
    }

    /**
     * @return list<BillLineData>
     */
    private function lineDataFrom(mixed $lines): array
    {
        if (! is_array($lines)) {
            throw BusinessRuleViolation::make(
                'bill-lines-not-a-list',
                'The lines must be supplied as a list.',
            );
        }

        return array_map(
            static fn (mixed $line): BillLineData => $line instanceof BillLineData
                ? $line
                : BillLineData::fromArray((array) $line),
            array_values($lines),
        );
    }

    /**
     * The bill's current lines as submission data, so an update that does not touch them still recomputes
     * correctly against a changed date or supplier.
     *
     * @return list<BillLineData>
     */
    private function lineDataFromExisting(Bill $bill): array
    {
        $bill->loadMissing('lines.taxCode');

        return array_values($bill->lines
            ->map(fn (BillLine $line): BillLineData => new BillLineData(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unit_price,
                expenseAccountId: $line->expense_account_id,
                // By code, not id: the bill date may have moved, and the code has to re-resolve against it.
                taxCode: $line->taxCode?->code,
                discountPercent: $line->discount_percent,
                discountAmount: $line->discount_amount,
                branchId: $line->branch_id,
            ))
            ->all());
    }
}
