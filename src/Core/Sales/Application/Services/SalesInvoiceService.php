<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\InvalidInvoiceDiscount;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
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
