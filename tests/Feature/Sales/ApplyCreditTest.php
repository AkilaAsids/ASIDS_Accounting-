<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
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
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Models\CreditApplication;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applying held credit to a later invoice — ADR 0016 §D, §E, §H (Stage 4), and §B (the credit_applications
 * table's CHECKs, RLS and full-freeze immutability).
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API ADR 0016 pins down:
 *
 *   - `ReceiptService::applyCredit(Company, ApplyCreditData{salesInvoiceId, amount, sourceReceiptId?}, ?User)
 *      : list<CreditApplication>` — one transaction, one `credit_application` + one JV per consumed record.
 *   - FIFO by the source receipt's `receipt_date` then `number`, consuming MULTIPLE records in one call;
 *     an explicit `sourceReceiptId` overrides FIFO and consumes only that record.
 *   - The apply posting is Dr Customer Advances / Cr Trade Receivables, a JournalVoucher, sourced to the
 *     `credit_application` (not the receipt, not the invoice — the source-uniqueness index forces it).
 *   - The target invoice's amount_paid/amount_due/status move forward as a normal allocation would; no bank
 *     debit is posted (the cash arrived when the receipt was recorded).
 *
 * THE PROBLEM-CODE CONTRACT (the spec the engineer builds to)
 * ----------------------------------------------------------
 * ADR 0016 §13 names `insufficientCredit` as a new code but not its stable string, and reuses the
 * `ReceiptCannotBeAllocated` family for the invoice-side refusals. This file fixes the strings, mirroring
 * `CancelReceiptTest`'s discipline and the existing `ReceiptCannotBeAllocated` codes verbatim:
 *
 *   insufficientCredit (new)         → 'receipt-insufficient-credit'   (requested > available; FIFO or specific)
 *   exceedsAmountDue (reused)        → 'receipt-allocation-exceeds-amount-due'
 *   toNonCollectableInvoice (reused) → 'receipt-allocation-invoice-not-collectable'
 *   crossCustomer (reused)           → 'receipt-allocation-cross-customer'
 *   crossCompany (reused)            → 'receipt-allocation-cross-company'
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup builds real held credit through `record()` (Stage 3). Today `record()` refuses a remainder, and
 * `ReceiptService::applyCredit()`, `ApplyCreditData`, `CreditApplication` and the `credit_applications` table
 * do not exist — so failures are "undefined method applyCredit", "class ApplyCreditData not found", a missing
 * table, or the Stage-3 remainder refusal. Every one names an absent decision, never a broken fixture.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);

    $this->revenue = applySuiteAccount('4100');
    $this->receivables = applySuiteAccount('1130');
    $this->bank = applySuiteAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function applySuiteAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

function applySuiteAdvancesAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::CUSTOMER_ADVANCES)
        ->firstOrFail();
}

function applySuiteInvoice(string $unitPrice, string $date = '2026-06-15', ?string $customerId = null, ?string $companyId = null): SalesInvoice
{
    $company = $companyId === null ? test()->company : CompanyForApply($companyId);

    $draft = app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($date),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) applySuiteAccount('4100', (string) $company->getKey())->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

function CompanyForApply(string $companyId): \Asids\Core\Organization\Domain\Models\Company
{
    return \Asids\Core\Organization\Domain\Models\Company::query()->whereKey($companyId)->firstOrFail();
}

/**
 * Records a receipt that leaves a remainder held as credit, by allocating `alloc` of `amount` to a
 * sacrificial invoice. The remainder (amount − alloc) is what these tests then apply elsewhere.
 */
function applyRemainderReceipt(SalesInvoice $sacrificial, string $alloc, string $amount, string $date = '2026-06-20', ?string $customerId = null): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF',
        allocations: [new ReceiptAllocationData(salesInvoiceId: (string) $sacrificial->getKey(), amount: $alloc)],
    ), test()->owner);
}

/**
 * Applies credit to a target invoice for a requested amount, optionally from a named source receipt.
 *
 * @return list<CreditApplication>
 */
function doApplyCredit(SalesInvoice $target, string $amount, ?CustomerReceipt $source = null, ?\Asids\Core\Identity\Domain\Models\User $actor = null): array
{
    return app(ReceiptService::class)->applyCredit(
        test()->company,
        new ApplyCreditData(
            salesInvoiceId: (string) $target->getKey(),
            amount: $amount,
            sourceReceiptId: $source !== null ? (string) $source->getKey() : null,
        ),
        $actor ?? test()->owner,
    );
}

function heldCreditRowFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

function creditApplicationCount(): int
{
    return (int) DB::table('credit_applications')->count();
}

describe('applying credit to an invoice', function (): void {
    it('clears a held record in full and moves the target invoice to Paid', function (): void {
        // AC-CR-3.2 — full application. Held 300, target due 300, no cash posted.
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00'); // remainder 300
        $target = applySuiteInvoice('300.00');

        doApplyCredit($target, '300.00');

        $target->refresh();

        expect($target->amount_paid)->toBe('300.0000')
            ->and($target->amount_due)->toBe('0.0000')
            ->and($target->status)->toBe(SalesInvoiceStatus::Paid);

        $held = heldCreditRowFor((string) $receipt->getKey());

        expect($held->remaining_amount)->toBe('0.0000')
            ->and($held->applied_amount)->toBe('300.0000')
            // A fully-consumed record stays active with remaining 0 — FIFO simply skips it (§D).
            ->and($held->status)->toBe('active');
    });

    it('applies part of a record, leaving remaining credit and the invoice partially paid', function (): void {
        // AC-CR-3.2 — partial application. Held 500, apply 300 to a 1,000 invoice.
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '500.00', '1000.00'); // remainder 500
        $target = applySuiteInvoice('1000.00');

        doApplyCredit($target, '300.00');

        $target->refresh();

        expect($target->amount_paid)->toBe('300.0000')
            ->and($target->amount_due)->toBe('700.0000')
            ->and($target->status)->toBe(SalesInvoiceStatus::PartiallyPaid);

        $held = heldCreditRowFor((string) $receipt->getKey());

        expect($held->remaining_amount)->toBe('200.0000')
            ->and($held->applied_amount)->toBe('300.0000');
    });

    it('posts a reclassification JV: Dr Customer Advances / Cr Trade Receivables, no bank line', function (): void {
        // AC-CR-3.2 — the credit already arrived as cash; applying it only reclassifies the liability to AR.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        $entry = JournalEntry::query()->with('lines')->findOrFail($application->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(2)
            ->and($entry->document_type)->toBe(DocumentType::JournalVoucher)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(300.0)
            ->and((float) $byAccount[applySuiteAdvancesAccount()->getKey()]->debit)->toBe(300.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(300.0)
            // No bank/cash line — the cash was booked at record time.
            ->and($byAccount->has($this->bank->getKey()))->toBeFalse();
    });

    it('sources the apply JV to the credit application, not the receipt or the invoice', function (): void {
        // §D / Problem #1 — each apply event is its own source document, or it collides at the source index.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        $entry = JournalEntry::query()->findOrFail($application->journal_entry_id);

        expect($entry->source_type)->toBe(CreditApplication::MORPH_ALIAS)
            ->and($entry->source_id)->toBe((string) $application->getKey());
    });
});

describe('FIFO and explicit source', function (): void {
    it('consumes the oldest receipt first across multiple records, one JV each', function (): void {
        // AC-CR-3.2 / §E — multi-receipt → one invoice, FIFO by receipt_date. Apply 500 over held 300 + 300.
        $sacrificial = applySuiteInvoice('2000.00');
        $older = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-15'); // remainder 300
        $newer = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-16'); // remainder 300
        $target = applySuiteInvoice('1000.00');

        $applications = doApplyCredit($target, '500.00');

        expect($applications)->toHaveCount(2)
            // Two distinct reclassification JVs, one per consumed record (Problem #1).
            ->and(JournalEntry::query()->where('source_type', CreditApplication::MORPH_ALIAS)->count())->toBe(2);

        // The older record is exhausted first; the newer takes only the balance.
        expect(heldCreditRowFor((string) $older->getKey())->remaining_amount)->toBe('0.0000')
            ->and(heldCreditRowFor((string) $newer->getKey())->remaining_amount)->toBe('100.0000');

        expect($target->refresh()->amount_paid)->toBe('500.0000');
    });

    it('breaks a same-date tie by the receipt number, consuming the lower number first', function (): void {
        // §E — receipt_date then number. Two same-date receipts; the first recorded (lower RCT) goes first.
        $sacrificial = applySuiteInvoice('2000.00');
        $first = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-15'); // RCT-...0001
        $second = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-15'); // RCT-...0002
        $target = applySuiteInvoice('1000.00');

        doApplyCredit($target, '300.00');

        expect(heldCreditRowFor((string) $first->getKey())->remaining_amount)->toBe('0.0000')
            ->and(heldCreditRowFor((string) $second->getKey())->remaining_amount)->toBe('300.0000');
    });

    it('consumes only the named record when a source receipt is given, overriding FIFO', function (): void {
        // §E — explicit source override. Name the NEWER receipt though an older one exists; FIFO is ignored.
        $sacrificial = applySuiteInvoice('2000.00');
        $older = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-15');
        $newer = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-16');
        $target = applySuiteInvoice('1000.00');

        doApplyCredit($target, '300.00', source: $newer);

        expect(heldCreditRowFor((string) $newer->getKey())->remaining_amount)->toBe('0.0000')
            // The older record is untouched — the override does not spill or fall back to FIFO.
            ->and(heldCreditRowFor((string) $older->getKey())->remaining_amount)->toBe('300.0000');
    });

    it('applies one receipt across two invoices in separate events', function (): void {
        // AC-CR-3.2 — one receipt → many invoices, each its own application against the same held record.
        $sacrificial = applySuiteInvoice('2000.00');
        $receipt = applyRemainderReceipt($sacrificial, '0.01', '1000.01'); // remainder 1000.00
        $targetA = applySuiteInvoice('400.00');
        $targetB = applySuiteInvoice('600.00');

        doApplyCredit($targetA, '400.00');
        doApplyCredit($targetB, '600.00');

        expect($targetA->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($targetB->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and(heldCreditRowFor((string) $receipt->getKey())->remaining_amount)->toBe('0.0000')
            ->and(heldCreditRowFor((string) $receipt->getKey())->applied_amount)->toBe('1000.0000')
            // Two applications against the one held record.
            ->and(creditApplicationCount())->toBe(2);
    });
});

describe('what applying refuses, writing nothing', function (): void {
    it('refuses when the customer holds no credit at all', function (): void {
        // §E — an empty FIFO set. This needs no remainder setup, so it is the clean proof applyCredit is absent.
        $target = applySuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => doApplyCredit($target, '100.00'));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit')
            ->and($target->refresh()->amount_paid)->toBe('0.0000')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses requesting more than the FIFO set can satisfy, leaving every record intact', function (): void {
        // AC-CR-3.3 — insufficient credit is a hard invariant, refused before anything is written.
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00'); // remainder 300
        $target = applySuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => doApplyCredit($target, '500.00'));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit')
            ->and(heldCreditRowFor((string) $receipt->getKey())->remaining_amount)->toBe('300.0000')
            ->and($target->refresh()->amount_paid)->toBe('0.0000')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses a named source with too little, without spilling into other records', function (): void {
        // §E — a named source that cannot satisfy the amount refuses; it does not fall back to FIFO.
        $sacrificial = applySuiteInvoice('2000.00');
        $named = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-15'); // remainder 300
        $other = applyRemainderReceipt($sacrificial, '300.00', '600.00', date: '2026-06-16'); // remainder 300
        $target = applySuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => doApplyCredit($target, '500.00', source: $named));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit')
            ->and(heldCreditRowFor((string) $named->getKey())->remaining_amount)->toBe('300.0000')
            ->and(heldCreditRowFor((string) $other->getKey())->remaining_amount)->toBe('300.0000')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses applying more than the target invoice still owes', function (): void {
        // §E cap — the total applied is bounded by the invoice's current amount_due, re-read under the lock.
        $sacrificial = applySuiteInvoice('2000.00');
        applyRemainderReceipt($sacrificial, '0.01', '1000.01'); // remainder 1000
        $target = applySuiteInvoice('300.00');

        $exception = catchPlatformException(fn () => doApplyCredit($target, '500.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and($exception->problemCode())->toBe('receipt-allocation-exceeds-amount-due')
            ->and($target->refresh()->amount_paid)->toBe('0.0000')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses applying a customer\'s credit to another customer\'s invoice', function (): void {
        // §K#5 — credit never crosses a customer. Named source belongs to Silva; target belongs to Perera.
        $sacrificial = applySuiteInvoice('1000.00');
        $silvaReceipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00'); // Silva's held credit

        $perera = app(CustomerService::class)->create($this->company, new CustomerData(name: 'Perera Stores', code: 'PERERA'));
        $pereraInvoice = applySuiteInvoice('1000.00', customerId: (string) $perera->getKey());

        $exception = catchPlatformException(fn () => doApplyCredit($pereraInvoice, '300.00', source: $silvaReceipt));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and($exception->problemCode())->toBe('receipt-allocation-cross-customer')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses a target invoice in another company', function (): void {
        // §K#5 — credit never crosses a company. Sibling company shares the tenant, so only the explicit
        // company check refuses it (both crossCompany and unknownInvoice live on ReceiptCannotBeAllocated).
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::parse('2026-06-15'));
        $siblingCustomer = app(CustomerService::class)->create($second, new CustomerData(name: 'X', code: 'X1'));
        $foreignInvoice = applySuiteInvoice('1000.00', customerId: (string) $siblingCustomer->getKey(), companyId: (string) $second->getKey());

        $exception = catchPlatformException(fn () => doApplyCredit($foreignInvoice, '300.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses applying to a fully paid invoice', function (): void {
        // §E — the target must be a live receivable. A settled invoice has nothing left to reduce.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');

        $target = applySuiteInvoice('300.00');
        // Settle the target with a normal fully-allocated receipt.
        app(ReceiptService::class)->record($this->company, new ReceiptData(
            customerId: (string) $this->customer->getKey(),
            receiptDate: CarbonImmutable::parse('2026-06-21'),
            amount: '300.00',
            paymentMethod: PaymentMethod::BankTransfer,
            bankAccountId: (string) $this->bank->getKey(),
            reference: 'SETTLE',
            allocations: [new ReceiptAllocationData(salesInvoiceId: (string) $target->getKey(), amount: '300.00')],
        ), $this->owner);

        expect($target->refresh()->status)->toBe(SalesInvoiceStatus::Paid);

        $exception = catchPlatformException(fn () => doApplyCredit($target, '100.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and($exception->problemCode())->toBe('receipt-allocation-invoice-not-collectable')
            ->and(creditApplicationCount())->toBe(0);
    });

    it('refuses a zero or negative apply amount', function (): void {
        // §B amount_positive_check intent, surfaced as a readable refusal before the DB backstop.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('1000.00');

        expect(catchPlatformException(fn () => doApplyCredit($target, '0.00')))
            ->toBeInstanceOf(\Asids\Core\Platform\Exceptions\BusinessRuleViolation::class);
        expect(catchPlatformException(fn () => doApplyCredit($target, '-100.00')))
            ->toBeInstanceOf(\Asids\Core\Platform\Exceptions\BusinessRuleViolation::class);

        expect(creditApplicationCount())->toBe(0);
    });

    it('cannot use the credit of a cancelled receipt', function (): void {
        // §G / §K#4 — cancelling zeroed the record; FIFO and explicit both filter it out.
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00'); // remainder 300, untouched

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $target = applySuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => doApplyCredit($target, '300.00', source: $receipt));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit')
            ->and(creditApplicationCount())->toBe(0);
    });
});

describe('over-consumption cannot happen', function (): void {
    it('consumes exactly the available amount when a stale view retries the same credit', function (): void {
        // AC-CR-3.4 / §H — the lock-and-re-read discipline. Modelled as RecordReceiptTest models its race:
        // the same held record is consumed once, then a second apply built from the pre-consumption view
        // re-reads the now-zero remaining and is refused rather than driving it negative.
        $sacrificial = applySuiteInvoice('2000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00'); // remainder 300
        $targetA = applySuiteInvoice('1000.00');
        $targetB = applySuiteInvoice('1000.00');

        doApplyCredit($targetA, '300.00', source: $receipt); // consumes the whole 300

        $exception = catchPlatformException(fn () => doApplyCredit($targetB, '300.00', source: $receipt));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit')
            // Never more than the 300 that existed; the second apply wrote nothing.
            ->and(heldCreditRowFor((string) $receipt->getKey())->remaining_amount)->toBe('0.0000')
            ->and(heldCreditRowFor((string) $receipt->getKey())->applied_amount)->toBe('300.0000')
            ->and(creditApplicationCount())->toBe(1);
    });

    it('rolls back the whole operation when the invoice cannot be saved after the JV posts', function (): void {
        // §J atomicity — a partial apply is impossible. Fails after reverse-of-record's forward mirror would
        // have posted and the application row written, which is the case worth proving: the rollback.
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $jvBefore = JournalEntry::query()->count();

        // Registered only now, so the remainder setup above is unaffected.
        SalesInvoice::updating(static function (): void {
            throw new RuntimeException('Simulated failure while writing the applied invoice');
        });

        expect(fn () => doApplyCredit($target, '300.00'))->toThrow(RuntimeException::class);

        expect(creditApplicationCount())->toBe(0)
            ->and($target->refresh()->amount_paid)->toBe('0.0000')
            ->and(heldCreditRowFor((string) $receipt->getKey())->remaining_amount)->toBe('300.0000')
            ->and(JournalEntry::query()->count())->toBe($jvBefore);
    });
});

describe('a replayed apply is a distinct event, not idempotent', function (): void {
    it('creates a second application and a second JV, documenting the HTTP idempotency gap', function (): void {
        // §I / §P — apply is not idempotent at the service level; two calls consume twice. A later HTTP slice
        // adds an idempotency key. Same record, same invoice, twice = two legitimate applications.
        $sacrificial = applySuiteInvoice('2000.00');
        $receipt = applyRemainderReceipt($sacrificial, '0.01', '1000.01'); // remainder 1000
        $target = applySuiteInvoice('1000.00');

        doApplyCredit($target, '300.00');
        doApplyCredit($target, '300.00');

        expect(creditApplicationCount())->toBe(2)
            ->and(JournalEntry::query()->where('source_type', CreditApplication::MORPH_ALIAS)->count())->toBe(2)
            ->and($target->refresh()->amount_paid)->toBe('600.0000')
            ->and(heldCreditRowFor((string) $receipt->getKey())->remaining_amount)->toBe('400.0000');
    });
});

describe('accounting integrity after applying', function (): void {
    it('keeps the Customer Advances balance equal to the live held remainders', function (): void {
        // §K#... — record credits Customer Advances by the remainder; apply debits it by what it consumes, so
        // the account's net credit balance always equals Σ remaining across active records.
        $sacrificial = applySuiteInvoice('2000.00');
        $receipt = applyRemainderReceipt($sacrificial, '0.01', '1000.01'); // remainder 1000 credited to advances
        $target = applySuiteInvoice('1000.00');

        doApplyCredit($target, '300.00'); // debits advances 300

        $advancesId = applySuiteAdvancesAccount()->getKey();
        $net = (float) DB::table('journal_lines')->where('account_id', $advancesId)->sum('credit')
            - (float) DB::table('journal_lines')->where('account_id', $advancesId)->sum('debit');

        $liveRemaining = (float) DB::table('receipt_held_credits')
            ->where('company_id', $this->company->getKey())
            ->where('status', 'active')
            ->sum('remaining_amount');

        expect($net)->toBe(700.0)
            ->and($net)->toBe($liveRemaining);
    });

    it('records an audit entry for each application', function (): void {
        // NFR audit — CreditApplication is Auditable, so applying leaves a trail an auditor can read.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        doApplyCredit($target, '300.00');

        expect(RowLevelSecurity::bypass(fn (): bool => AuditLog::query()
            ->where('auditable_type', CreditApplication::MORPH_ALIAS)
            ->exists()))->toBeTrue();
    });
});

describe('the CreditApplication model and its table', function (): void {
    it('registers its morph alias so a journal entry can cite it', function (): void {
        expect(\Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel(CreditApplication::MORPH_ALIAS))
            ->toBe(CreditApplication::class)
            ->and(CreditApplication::MORPH_ALIAS)->toBe('credit_application');
    });

    it('relates to its held credit, invoice and journal entry, casting the amount to four decimals', function (): void {
        $sacrificial = applySuiteInvoice('1000.00');
        $receipt = applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        expect($application->amount)->toBe('300.0000')
            ->and((string) $application->invoice->getKey())->toBe((string) $target->getKey())
            ->and((string) $application->heldCredit->customer_receipt_id)->toBe((string) $receipt->getKey())
            ->and($application->journalEntry->getKey())->toBe($application->journal_entry_id);
    });

    it('is stopped by the database from posting a second JV for the same application', function (): void {
        // §H / Problem #1 — the source-uniqueness index, exercised directly. The same backstop RecordReceiptTest
        // proves for the receipt: not any in-application check, the partial unique index over source_id.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        $secondPosting = fn () => app(PostingService::class)->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-20'),
            description: 'A racing second posting of the same application',
            lines: [
                new JournalLineData(accountId: (string) applySuiteAdvancesAccount()->getKey(), debit: Money::of('300.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->receivables->getKey(), credit: Money::of('300.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
            source: SourceDocument::for($application),
        ), $this->owner);

        expect($secondPosting)->toThrow(QueryException::class);
        expect(JournalEntry::query()->where('source_id', (string) $application->getKey())->count())->toBe(1);
    });

    it('refuses a second application row sharing a journal entry, via the UNIQUE column', function (): void {
        // §B — journal_entry_id UNIQUE, the one-posting-per-application backstop.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];
        $row = DB::table('credit_applications')->where('id', $application->getKey())->first();

        expect(fn () => DB::table('credit_applications')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $row->tenant_id,
            'company_id' => $row->company_id,
            'customer_id' => $row->customer_id,
            'receipt_held_credit_id' => $row->receipt_held_credit_id,
            'sales_invoice_id' => $row->sales_invoice_id,
            'currency_code' => $row->currency_code,
            'amount' => '1.0000',
            'journal_entry_id' => $row->journal_entry_id, // the collision
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('is frozen: neither updated nor deleted once it exists', function (): void {
        // §B full-freeze immutability — an application is a historical fact; reversing one is a new posting,
        // out of scope this wave.
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        expect(fn () => DB::table('credit_applications')->where('id', $application->getKey())
            ->update(['amount' => '1.0000']))->toThrow(QueryException::class);

        expect(fn () => DB::table('credit_applications')->where('id', $application->getKey())->delete())
            ->toThrow(QueryException::class);
    });

    it('isolates an application row from another tenant by its own RLS policy', function (): void {
        $sacrificial = applySuiteInvoice('1000.00');
        applyRemainderReceipt($sacrificial, '700.00', '1000.00');
        $target = applySuiteInvoice('300.00');

        $application = doApplyCredit($target, '300.00')[0];

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(DB::table('credit_applications')->where('id', $application->getKey())->exists())->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(DB::table('credit_applications')->where('id', $application->getKey())->exists())->toBeTrue();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('credit_applications'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role (asids_app).'
    );
});
