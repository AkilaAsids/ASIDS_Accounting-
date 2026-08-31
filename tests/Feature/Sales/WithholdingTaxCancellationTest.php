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
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a WHT receipt, and apply-credit's non-interaction with WHT — ADR 0017 §E, Stage 4 (TEST-ONLY, no
 * production code).
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED behaviour ADR 0017 §E pins down:
 *
 *   - Cancellation reverses the WHT debit GENERICALLY. `PostingService::reverse()` mirrors every line of the
 *     receipt's single entry with sides swapped and amounts copied, so the WHT debit becomes a `Cr WHT
 *     Receivable` in the mirror — no WHT-specific reversal code, exactly how ADR 0016's Customer Advances credit
 *     is already reversed (AC-WHT-5.1). The WHT Receivable balance returns to what it was before the receipt, a
 *     delta-restore by construction of the whole-entry mirror, never a snapshot (AC-WHT-5.2).
 *   - NO "already applied" guard for WHT (AC-WHT-5.3). A WHT receivable is never consumed by anything this wave;
 *     there is no "apply WHT" operation. So the over-reversal hazard that forced ADR 0016's
 *     heldCreditAlreadyApplied() guard cannot arise for WHT, and cancellation is never blocked on WHT grounds.
 *   - Apply-credit posts NO WHT line (AC-WHT-6.1). WHT is withheld only when actual cash is remitted — i.e. only
 *     at record time. Applying a WHT-history customer's held credit posts Dr Customer Advances / Cr Trade
 *     Receivables only: no WHT line, no WHT column read, no bank line.
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup builds a WHT receipt through `record()` (Stage 3) against the `1180 WHT Receivable` account (Stage 1)
 * and the `wht_amount` column (Stage 2) — none of which exist yet. Failures are the absent DTO field
 * ("unknown named parameter whtAmount"), the undefined `Account::WHT_RECEIVABLE` constant, or a missing column.
 * Once Stages 1–3 land, Stage 4 is proven with NO new code — the whole-entry mirror and apply-credit are
 * already generic. Each failure names an absent decision, never a broken fixture.
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

    $this->revenue = whtCxlAccount('4100');
    $this->receivables = whtCxlAccount('1130');
    $this->bank = whtCxlAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function whtCxlAccount(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * The WHT Receivable account by key — errors RED until the Stage-1 key and account exist.
 */
function whtCxlReceivableAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::WHT_RECEIVABLE)
        ->firstOrFail();
}

function whtCxlAdvancesAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::CUSTOMER_ADVANCES)
        ->firstOrFail();
}

function whtCxlInvoice(string $unitPrice, string $date = '2026-06-10', ?string $customerId = null): SalesInvoice
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
 * Records a receipt from pre-built allocation lines (each may carry its own WHT), through the shipped path.
 *
 * @param  list<ReceiptAllocationData>  $allocations
 */
function whtCxlReceipt(array $allocations, string $amount, string $date = '2026-06-15'): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF',
        allocations: $allocations,
    ), test()->owner);
}

function whtCxlApplyCredit(SalesInvoice $target, string $amount, ?CustomerReceipt $source = null): array
{
    return app(ReceiptService::class)->applyCredit(test()->company, new ApplyCreditData(
        salesInvoiceId: (string) $target->getKey(),
        amount: $amount,
        sourceReceiptId: $source !== null ? (string) $source->getKey() : null,
    ), test()->owner);
}

function whtCxlHeldCreditFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

/**
 * The net movement on the WHT Receivable account across the whole ledger: debit − credit (it is debit-normal,
 * so a held receivable is positive). Zero after a receipt and its reversal net out.
 */
function whtCxlReceivableNet(): float
{
    $id = whtCxlReceivableAccount()->getKey();

    return (float) DB::table('journal_lines')->where('account_id', $id)->sum('debit')
        - (float) DB::table('journal_lines')->where('account_id', $id)->sum('credit');
}

describe('cancelling a WHT receipt reverses the WHT line generically (AC-WHT-5.1)', function (): void {
    it('mirrors the WHT debit as a Cr WHT Receivable so the pair nets to zero', function (): void {
        // Gross 1000 / WHT 50 / cash 950 → Dr Bank 950 / Dr WHT 50 / Cr AR 1000. The whole-entry mirror swaps
        // every side, so the WHT debit becomes a Cr WHT Receivable 50 — no WHT-specific reversal code.
        $invoice = whtCxlInvoice('1000.00');
        $receipt = whtCxlReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00');

        $original = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);

        $this->receipts->cancel($receipt, 'Recorded against the wrong invoice', $this->owner);

        $mirror = JournalEntry::query()->with('lines')->findOrFail($original->fresh()->reversed_by_entry_id);
        $mirrorByAccount = $mirror->lines->keyBy('account_id');
        $whtId = whtCxlReceivableAccount()->getKey();

        expect($mirror->lines()->count())->toBe(3)
            ->and($mirror->lines()->count())->toBe($original->lines()->count())
            // The original debited WHT Receivable; the mirror credits it back.
            ->and((float) $mirrorByAccount[$whtId]->credit)->toBe(50.0);

        // Net movement on WHT Receivable across record + reversal is zero — the balance returns to prior.
        expect(whtCxlReceivableNet())->toBe(0.0);
    });

    it('restores the WHT Receivable balance by delta, leaving another live receipt\'s WHT intact (AC-WHT-5.2)', function (): void {
        // Two WHT receipts add 50 and 30 to WHT Receivable (balance 80). Cancelling the first reverses only its
        // 50 — a delta, never a snapshot-to-zero — so the second's 30 survives.
        $invoiceA = whtCxlInvoice('1000.00');
        $invoiceB = whtCxlInvoice('1000.00');
        $receiptA = whtCxlReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoiceA->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00', date: '2026-06-15');
        $receiptB = whtCxlReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoiceB->getKey(), amount: '1000.00', whtAmount: '30.00'),
        ], '970.00', date: '2026-06-16');

        expect(whtCxlReceivableNet())->toBe(80.0);

        $this->receipts->cancel($receiptA, 'Receipt A recorded in error', $this->owner);

        // Only A's 50 was reversed; B's 30 is untouched.
        expect(whtCxlReceivableNet())->toBe(30.0)
            ->and($receiptB->refresh()->status)->toBe('posted')
            ->and(JournalEntry::query()->findOrFail($receiptB->journal_entry_id)->status)
            ->toBe(JournalEntryStatus::Posted);
    });
});

describe('WHT has no "already applied" guard on cancellation (AC-WHT-5.3)', function (): void {
    it('cancels a WHT receipt that also holds unapplied credit, reversing both lines with no WHT guard', function (): void {
        // WHT has no consumption lifecycle, so nothing about the withheld amount can block a cancellation — the
        // over-reversal hazard that forced ADR 0016's heldCreditAlreadyApplied() guard cannot arise for WHT.
        // Gross 1000: alloc 700 / WHT 50 / cash 950 → settlement 1000, remainder 300 held. Four lines. The
        // unapplied held credit is Case 1 (delta-zeroed); the WHT is reversed alongside it, generically.
        $invoice = whtCxlInvoice('1000.00');
        $receipt = whtCxlReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '700.00', whtAmount: '50.00'),
        ], '950.00');

        expect(whtCxlHeldCreditFor((string) $receipt->getKey())->remaining_amount)->toBe('300.0000');

        $cancelled = $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        expect($cancelled->status)->toBe('cancelled')
            // The WHT debit and the Customer Advances credit both net to zero across record + reversal.
            ->and(whtCxlReceivableNet())->toBe(0.0)
            // The held-credit branch neither saw nor needed WHT — it delta-zeroed and cancelled as usual.
            ->and(whtCxlHeldCreditFor((string) $receipt->getKey())->remaining_amount)->toBe('0.0000')
            ->and(whtCxlHeldCreditFor((string) $receipt->getKey())->status)->toBe('cancelled')
            ->and($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Issued);
    });
});

describe('apply-credit is entirely unaffected by WHT (AC-WHT-6.1)', function (): void {
    it('posts Dr Customer Advances / Cr AR only for a WHT-history customer, with no WHT line', function (): void {
        // The held credit being applied is the untaxed remainder of an earlier cash receipt that itself carried
        // WHT. Applying it reclassifies the liability to AR — no cash arrives, so no WHT is withheld and no WHT
        // line is posted. Sacrificial gross 1000: alloc 700 / WHT 50 / cash 950 → remainder 300 held.
        $sacrificial = whtCxlInvoice('1000.00');
        whtCxlReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $sacrificial->getKey(), amount: '700.00', whtAmount: '50.00'),
        ], '950.00');

        $target = whtCxlInvoice('300.00');

        $application = whtCxlApplyCredit($target, '300.00')[0];

        $entry = JournalEntry::query()->with('lines')->findOrFail($application->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(2)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(300.0)
            ->and((float) $byAccount[whtCxlAdvancesAccount()->getKey()]->debit)->toBe(300.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(300.0)
            // No WHT Receivable line and no bank line — WHT is record-only, and no cash arrives on an apply.
            ->and($byAccount->has(whtCxlReceivableAccount()->getKey()))->toBeFalse()
            ->and($byAccount->has($this->bank->getKey()))->toBeFalse();

        expect($target->refresh()->status)->toBe(SalesInvoiceStatus::Paid);
    });
});
