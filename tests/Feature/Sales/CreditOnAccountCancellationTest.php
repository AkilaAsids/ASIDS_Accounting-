<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Sales\Application\DTOs\ApplyCreditData;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBeCancelled;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeCancelled;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a receipt that carries held credit, and the invoices that received applied credit — ADR 0016 §G.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED behaviour ADR 0016 §G pins down:
 *
 *   - Case 1 — receipt cancelled, credit UNTOUCHED: `PostingService::reverse()` mirrors the WHOLE original JV,
 *     so the Customer Advances credit is unwound at the GL automatically; in the same transaction the held
 *     record is delta-zeroed (remaining → 0) and marked `cancelled` — the credit-side analogue of the invoice
 *     delta-restore (ADR 0015 §C).
 *   - Case 2 — receipt cancelled, credit ALREADY APPLIED (in part or full): REFUSED with a new
 *     `ReceiptCannotBeCancelled::heldCreditAlreadyApplied()`, because `reverse()` can only mirror the entry
 *     whole and would over-reverse the subledger.
 *   - Case 3 — invoice cancelled after credit was applied to it: already refused by the existing
 *     `SalesInvoiceService::cancel()` `partiallyPaid()` guard (amount_paid > 0). NO NEW CODE.
 *
 * THE PROBLEM-CODE CONTRACT (the spec the engineer builds to)
 * ----------------------------------------------------------
 * ADR 0016 §G names the new guard but not its stable string. This file fixes it, following the receipt
 * family's `receipt-` convention and mirroring `wouldReverseBelowZero`'s existing code shape:
 *
 *   heldCreditAlreadyApplied (new) → 'receipt-held-credit-already-applied'
 *   (existing) partiallyPaid       → 'invoice-partially-paid'
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup builds real held credit through `record()` (Stage 3), applies it through `applyCredit()` (Stage 4),
 * and cancels through `cancel()`'s new held-credit branch (Stage 5) — none of which exist yet. Failures are
 * the Stage-3 remainder refusal, "undefined method applyCredit", a missing `receipt_held_credits` table, or a
 * cancel that does not yet touch held credit. Each names an absent decision.
 *
 * Dates frozen exactly as CancelReceiptTest: invoices 2026-06-10, receipts 2026-06-15, "today" 2026-06-20.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-20 09:00:00'));

    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);
    $this->invoices = app(SalesInvoiceService::class);

    $this->revenue = cxlCreditAccount('4100');
    $this->receivables = cxlCreditAccount('1130');
    $this->bank = cxlCreditAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function cxlCreditAccount(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

function cxlCreditAdvancesAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::CUSTOMER_ADVANCES)
        ->firstOrFail();
}

function cxlCreditInvoice(string $unitPrice, string $date = '2026-06-10', ?string $customerId = null): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($date),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * A posted receipt that allocated `alloc` to a sacrificial invoice and holds `amount − alloc` as credit.
 */
function cxlRemainderReceipt(SalesInvoice $sacrificial, string $alloc, string $amount, string $date = '2026-06-15'): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF',
        allocations: [new ReceiptAllocationData(salesInvoiceId: (string) $sacrificial->getKey(), amount: $alloc)],
    ), test()->owner);
}

function cxlApplyCredit(SalesInvoice $target, string $amount, ?CustomerReceipt $source = null): array
{
    return app(ReceiptService::class)->applyCredit(test()->company, new ApplyCreditData(
        salesInvoiceId: (string) $target->getKey(),
        amount: $amount,
        sourceReceiptId: $source !== null ? (string) $source->getKey() : null,
    ), test()->owner);
}

function cxlHeldCreditFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

describe('Case 1 — cancelling a receipt whose credit is untouched', function (): void {
    it('delta-zeroes the held record and marks it cancelled', function (): void {
        // AC-CR-5.1 / §G Case 1 — remaining → 0, status → cancelled, using the record's own current remaining.
        $sacrificial = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($sacrificial, '700.00', '1000.00'); // remainder 300, untouched

        expect(cxlHeldCreditFor((string) $receipt->getKey())->remaining_amount)->toBe('300.0000');

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $held = cxlHeldCreditFor((string) $receipt->getKey());

        expect($held->remaining_amount)->toBe('0.0000')
            ->and($held->status)->toBe('cancelled');
    });

    it('reverses the Customer Advances credit as part of mirroring the whole entry', function (): void {
        // §G Case 1 — reverse() mirrors every line, so the remainder credit is unwound at the GL with no
        // special posting. The mirror carries a Customer Advances DEBIT, and the account nets to zero.
        $sacrificial = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($sacrificial, '700.00', '1000.00');

        $original = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $mirror = JournalEntry::query()->with('lines')->findOrFail($original->fresh()->reversed_by_entry_id);
        $advancesId = cxlCreditAdvancesAccount()->getKey();
        $mirrorByAccount = $mirror->lines->keyBy('account_id');

        expect($mirror->lines()->count())->toBe(3)
            // The original credited Customer Advances; the mirror debits it back.
            ->and((float) $mirrorByAccount[$advancesId]->debit)->toBe(300.0);

        // Net movement on Customer Advances across record + reversal is zero.
        $net = (float) DB::table('journal_lines')->where('account_id', $advancesId)->sum('credit')
            - (float) DB::table('journal_lines')->where('account_id', $advancesId)->sum('debit');

        expect($net)->toBe(0.0);
    });

    it('restores the sacrificial invoice by delta alongside zeroing the credit', function (): void {
        // AC-CR-5.1 / AC-CR-6.3 — the allocated portion is restored exactly as ADR 0015 does, and the held
        // credit is unwound in the SAME transaction: nothing is left half-done.
        $sacrificial = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($sacrificial, '700.00', '1000.00');

        expect($sacrificial->refresh()->amount_paid)->toBe('700.0000');

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $sacrificial->refresh();

        expect($sacrificial->amount_paid)->toBe('0.0000')
            ->and($sacrificial->amount_due)->toBe('1000.0000')
            ->and($sacrificial->status)->toBe(SalesInvoiceStatus::Issued)
            ->and(cxlHeldCreditFor((string) $receipt->getKey())->remaining_amount)->toBe('0.0000');
    });

    it('zeroes only this receipt\'s credit, leaving another receipt\'s credit intact (delta, not snapshot)', function (): void {
        // AC-CR-5.1 multi-event proof — the delta discipline: cancelling one record must not disturb another.
        $sacrificialA = cxlCreditInvoice('1000.00');
        $sacrificialB = cxlCreditInvoice('1000.00');
        $receiptA = cxlRemainderReceipt($sacrificialA, '700.00', '1000.00', date: '2026-06-15'); // held 300
        $receiptB = cxlRemainderReceipt($sacrificialB, '600.00', '1000.00', date: '2026-06-16'); // held 400

        $this->receipts->cancel($receiptA, 'Receipt A recorded in error', $this->owner);

        expect(cxlHeldCreditFor((string) $receiptA->getKey())->remaining_amount)->toBe('0.0000')
            ->and(cxlHeldCreditFor((string) $receiptA->getKey())->status)->toBe('cancelled')
            // B is untouched.
            ->and(cxlHeldCreditFor((string) $receiptB->getKey())->remaining_amount)->toBe('400.0000')
            ->and(cxlHeldCreditFor((string) $receiptB->getKey())->status)->toBe('active')
            ->and($receiptB->refresh()->status)->toBe('posted');
    });
});

describe('Case 2 — cancelling a receipt whose credit was already applied', function (): void {
    it('refuses when the credit was partially applied, reversing nothing', function (): void {
        // §G Case 2 / AC-CR-5.2 — the wouldReverseBelowZero analogue for the credit balance.
        $sacrificial = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($sacrificial, '700.00', '1000.00'); // held 300
        $target = cxlCreditInvoice('1000.00');

        cxlApplyCredit($target, '100.00'); // 100 of the 300 now applied

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Recorded in error', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-held-credit-already-applied')
            // Nothing reversed, receipt still posted, held credit still shows the applied amount.
            ->and($receipt->refresh()->status)->toBe('posted')
            ->and(JournalEntry::query()->findOrFail($receipt->journal_entry_id)->status)->toBe(JournalEntryStatus::Posted)
            ->and(cxlHeldCreditFor((string) $receipt->getKey())->applied_amount)->toBe('100.0000');
    });

    it('refuses when the credit was fully applied', function (): void {
        // §G Case 2 — the same refusal whether part or all of the credit was consumed.
        $sacrificial = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($sacrificial, '700.00', '1000.00'); // held 300
        $target = cxlCreditInvoice('1000.00');

        cxlApplyCredit($target, '300.00'); // whole credit applied

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Recorded in error', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-held-credit-already-applied')
            ->and($receipt->refresh()->status)->toBe('posted');
    });
});

describe('Case 3 — cancelling an invoice that received applied credit', function (): void {
    it('is refused by the existing partiallyPaid guard, with no new code', function (): void {
        // §G Case 3 — applying credit raised the target's amount_paid, and SalesInvoiceService::cancel() already
        // refuses any invoice with amount_paid > 0. This holds however many applications contributed.
        $sacrificial = cxlCreditInvoice('1000.00');
        cxlRemainderReceipt($sacrificial, '700.00', '1000.00'); // held 300
        $target = cxlCreditInvoice('1000.00');

        cxlApplyCredit($target, '300.00'); // target now amount_paid 300, PartiallyPaid

        expect($target->refresh()->amount_paid)->toBe('300.0000');

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($target->refresh(), 'Cancel despite applied credit', $this->owner)
        );

        expect($exception)->toBeInstanceOf(InvoiceCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('invoice-partially-paid')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });
});

describe('regression: cancellation unrelated to held credit', function (): void {
    it('still cancels a fully-allocated receipt that left no credit', function (): void {
        // AC-CR-6.3 — a receipt with no remainder has no held-credit branch to run; cancellation is unchanged.
        $invoice = cxlCreditInvoice('1000.00');
        $receipt = cxlRemainderReceipt($invoice, '1000.00', '1000.00'); // fully allocated, no held credit

        expect(cxlHeldCreditFor((string) $receipt->getKey()))->toBeNull();

        $cancelled = $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        expect($cancelled->status)->toBe('cancelled')
            ->and($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Issued);
    });
});
