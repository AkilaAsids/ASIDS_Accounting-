<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
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
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a customer receipt net of customer-withheld income tax — ADR 0017 §C (the settlement invariant),
 * §D (the record path + posting), §B (the two allocation columns and their CHECKs). Stages 3 and 2.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API ADR 0017 pins down:
 *
 *   - `ReceiptAllocationData(salesInvoiceId, amount, whtAmount?, whtCertificateReference?)` — two new optional
 *     fields; a caller supplying neither behaves exactly as before.
 *   - `ReceiptService::record()` validates per-allocation WHT (>= 0, <= its allocation, at currency_precision),
 *     applies the settlement invariant `settlement = amount + Σ wht`, and posts, only when Σ wht > 0, one
 *     netted `Dr WHT Receivable(Σ wht)` line:
 *         Dr Bank(net cash) + Dr WHT Receivable(Σ wht) = Cr Trade Receivables(Σ alloc gross) [+ Cr Customer Advances(remainder)]
 *   - The newly-accepted state (§C): `Σ allocations > amount` is VALID exactly when WHT covers the gap
 *     (`Σ alloc ≤ amount + Σ wht`), because gross settled legitimately exceeds net cash by the withheld tax.
 *   - `receipt_allocations.wht_amount numeric(19,4) NOT NULL DEFAULT 0` (CHECK >= 0, CHECK <= amount) and
 *     `wht_certificate_reference varchar(120) NULL`, frozen by the EXISTING full-freeze trigger with no change.
 *
 * THE PROBLEM-CODE CONTRACT (the spec the engineer builds to)
 * ----------------------------------------------------------
 * ADR 0017 §D names the new factories but not their stable `problemCode()` strings. This file fixes them,
 * mirroring the receipt family's convention (`ReceiptCannotBeAllocated` codes are `receipt-allocation-…`;
 * `ReceiptCannotBeRecorded` over-allocation is `receipt-over-…`) and the discipline `CancelReceiptTest` set:
 *
 *   negativeWithholding (new, Allocated)          → 'receipt-allocation-withholding-negative'
 *   withholdingExceedsAllocation (new, Allocated) → 'receipt-allocation-withholding-exceeds-allocation'
 *   overAllocatedBeyondSettlement (new, Recorded) → 'receipt-over-allocated-beyond-settlement'
 *   withoutWhtReceivableAccount (new, Posted)     → 'receipt-without-wht-receivable-account'
 *   amountExceedsCurrencyPrecision (REUSED)       → 'receipt-amount-exceeds-currency-precision'
 *   overAllocated (REUSED, unchanged for wht = 0) → 'receipt-over-allocated'
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup runs through the shipped `record()`/`issue()`. A WHT receipt fails because `ReceiptAllocationData` has
 * no `whtAmount` (an "unknown named parameter" error names the absent DTO field); the posting tests fail because
 * `Account::WHT_RECEIVABLE` is undefined and no WHT line is emitted; the DB-guard tests fail their
 * `hasColumn('wht_amount')` precondition until the migration lands. Every failure names an absent decision.
 *
 * A handful of REGRESSION GUARDS use the plain `ReceiptAllocationData(salesInvoiceId, amount)` and are GREEN
 * from the start by design — they prove the no-WHT path is byte-identical (AC-WHT-1.2) once the wave lands, and
 * are labelled as such.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);

    $this->revenue = whtSuiteAccount('4100');
    $this->receivables = whtSuiteAccount('1130');
    $this->bank = whtSuiteAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function whtSuiteAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * The WHT Receivable account, resolved by key exactly as the posting map must. Errors RED until the key and the
 * account exist (Stage 1).
 */
function whtSuiteReceivableAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::WHT_RECEIVABLE)
        ->firstOrFail();
}

/**
 * The Customer Advances account (ADR 0016, already shipped on this branch), for the compose-with-remainder
 * tests where a WHT debit and a Customer Advances credit coexist in one entry.
 */
function whtSuiteAdvancesAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::CUSTOMER_ADVANCES)
        ->firstOrFail();
}

function whtSuiteInvoice(string $unitPrice = '1000.00', string $date = '2026-06-15', ?string $customerId = null): SalesInvoice
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
 * Records a receipt for the suite's customer from pre-built allocation lines — each of which may carry its own
 * WHT and certificate reference (the intended per-allocation API, Gate-1 #3).
 *
 * @param  list<ReceiptAllocationData>  $allocations
 */
function whtSuiteReceipt(array $allocations, string $amount, string $date = '2026-06-20'): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF-1',
        allocations: $allocations,
    ), test()->owner);
}

/**
 * The single allocation row for a receipt, as a raw record — so `wht_amount` and `wht_certificate_reference`
 * are read straight from the column (RED with "column does not exist" until the migration lands).
 */
function whtAllocationRowFor(string $receiptId): ?object
{
    return DB::table('receipt_allocations')->where('customer_receipt_id', $receiptId)->first();
}

function whtHeldCreditFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

describe('a single-invoice WHT receipt (AC-WHT-1.1)', function (): void {
    it('posts Dr Bank net + Dr WHT + Cr AR gross, balanced, with no remainder', function (): void {
        // Gross-1000 invoice, WHT 50, net cash 950 → the invoice is settled in full, 50 held as a receivable.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(3)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1000.0)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(950.0)
            ->and((float) $byAccount[whtSuiteReceivableAccount()->getKey()]->debit)->toBe(50.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0)
            // No Customer Advances line: settlement exactly equals Σ allocations, so the remainder is zero.
            ->and($byAccount->has(whtSuiteAdvancesAccount()->getKey()))->toBeFalse();

        // The gross AR is fully settled even though only net cash arrived (Gate-1 #3).
        expect($invoice->refresh()->amount_paid)->toBe('1000.0000')
            ->and($invoice->amount_due)->toBe('0.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Paid);

        // No remainder held.
        expect(whtHeldCreditFor((string) $receipt->getKey()))->toBeNull();
    });

    it('persists the WHT amount on the allocation row', function (): void {
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00');

        expect(whtAllocationRowFor((string) $receipt->getKey())->wht_amount)->toBe('50.0000');
    });
});

describe('the newly-accepted settlement state (ADR 0017 §C)', function (): void {
    it('accepts Σ allocations (1000) greater than the cash amount (950) when WHT covers the gap', function (): void {
        // THE ONE NEWLY-ACCEPTED STATE. Under ADR 0016 this was ALWAYS an over-allocation error (Σ alloc >
        // amount); ADR 0017 permits it exactly when WHT makes up the difference: settlement = 950 + 50 = 1000 =
        // Σ alloc, remainder 0. Made visible here so the semantic flip reads as a reviewed decision.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00');

        expect(DB::table('customer_receipts')->count())->toBe(1)
            ->and($receipt->amount)->toBe('950.0000')
            ->and(whtHeldCreditFor((string) $receipt->getKey()))->toBeNull();
    });

    it('still refuses when allocations exceed settlement = amount + Σ wht', function (): void {
        // Gross 1000, WHT 50, but only 900 cash. settlement = 950 < Σ alloc 1000 — the net value applied to
        // invoices (1000 − 50 = 950) exceeds the cash received (900). Refused, nothing written.
        $invoice = whtSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '900.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('receipt-over-allocated-beyond-settlement');
        expect(DB::table('customer_receipts')->count())->toBe(0)
            ->and($invoice->refresh()->amount_paid)->toBe('0.0000');
    });

    it('still refuses an ordinary over-allocation with the unchanged code when no WHT is present [regression guard]', function (): void {
        // GREEN FROM THE START. The wht = 0 over-allocation path — Σ alloc (1000) > amount (600) with no WHT —
        // keeps ADR 0016's exact message and code `receipt-over-allocated`, untouched by the settlement rule.
        $invoice = whtSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '600.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('receipt-over-allocated');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });
});

describe('multi-invoice, WHT per allocation (AC-WHT-1.7 / 2.2)', function (): void {
    it('nets one WHT debit and one AR credit while moving each invoice by its own gross allocation', function (): void {
        // Two invoices, each with its own WHT certificate. Invoice A gross 1000 / WHT 100 (net 900); invoice B
        // gross 500 / WHT 50 (net 450). Net cash 1350; settlement 1500 = Σ alloc; one netted WHT line 150.
        $a = whtSuiteInvoice('1000.00');
        $b = whtSuiteInvoice('500.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $a->getKey(), amount: '1000.00', whtAmount: '100.00', whtCertificateReference: 'WHT-A'),
            new ReceiptAllocationData(salesInvoiceId: (string) $b->getKey(), amount: '500.00', whtAmount: '50.00', whtCertificateReference: 'WHT-B'),
        ], '1350.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(3)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1500.0)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1350.0)
            // One WHT Receivable line for the sum, not one per invoice — WHT Receivable is a single GL account,
            // the per-invoice detail lives in the subledger, exactly as Σ allocations is one AR line.
            ->and((float) $byAccount[whtSuiteReceivableAccount()->getKey()]->debit)->toBe(150.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1500.0);

        // Each invoice's gross balance moved by its own allocation.amount, independent of its WHT.
        expect($a->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($a->amount_paid)->toBe('1000.0000')
            ->and($b->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($b->amount_paid)->toBe('500.0000');
    });
});

describe('WHT and a Customer Advances remainder in one entry', function (): void {
    it('posts all four line types and balances', function (): void {
        // The compose case: WHT on the debit side (ADR 0017) and a remainder on the credit side (ADR 0016) in
        // one entry. Gross 1000 / WHT 100, but 1000 cash arrives. settlement = 1100; Σ alloc 1000; remainder
        // 100 held as customer advances. Dr Bank 1000 / Dr WHT 100 / Cr AR 1000 / Cr Customer Advances 100.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '100.00'),
        ], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(4)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1100.0)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[whtSuiteReceivableAccount()->getKey()]->debit)->toBe(100.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0)
            ->and((float) $byAccount[whtSuiteAdvancesAccount()->getKey()]->credit)->toBe(100.0);

        // The remainder is pure excess cash held as a liability — WHT never attaches to it (Gate-1 #4).
        expect(whtHeldCreditFor((string) $receipt->getKey())->original_amount)->toBe('100.0000');
    });

    it('holds the remainder at currency precision with WHT present, subledger and ledger agreeing', function (): void {
        // The phantom-remainder guard (ADR 0016 Gate-2 amendment) inherited from day one. Gross 1000 / WHT 50,
        // cash 1000.50 → settlement 1050.50, remainder 50.50, all at the company's 2-dp currency_precision.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '1000.50');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');
        $held = whtHeldCreditFor((string) $receipt->getKey());

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.5)
            ->and((float) $byAccount[whtSuiteReceivableAccount()->getKey()]->debit)->toBe(50.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0)
            ->and((float) $byAccount[whtSuiteAdvancesAccount()->getKey()]->credit)->toBe(50.5)
            // The held-credit subledger row equals the posted Customer Advances line exactly.
            ->and($held->original_amount)->toBe('50.5000')
            ->and((float) $byAccount[whtSuiteAdvancesAccount()->getKey()]->credit)->toBe((float) $held->original_amount)
            // And the entry balances at the currency precision.
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1050.5);
    });
});

describe('the certificate reference is independent of the WHT amount (Gate-2 fork a)', function (): void {
    it('accepts a certificate reference with no withholding amount', function (): void {
        // Fork (a): no cross-field constraint — a certificate may be recorded before the amount is finalised.
        // Zero WHT posts the identical two-line entry, and the reference is captured on the allocation.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtCertificateReference: 'WHT-CERT-Z'),
        ], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);

        // Σ wht = 0, so the WHT debit line is omitted — byte-identical to a no-WHT receipt.
        expect($entry->lines)->toHaveCount(2);

        $row = whtAllocationRowFor((string) $receipt->getKey());

        expect($row->wht_amount)->toBe('0.0000')
            ->and($row->wht_certificate_reference)->toBe('WHT-CERT-Z');
    });

    it('accepts withholding with no certificate reference', function (): void {
        // The mirror of the above: a WHT amount with the reference still to come.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00');

        $row = whtAllocationRowFor((string) $receipt->getKey());

        expect($row->wht_amount)->toBe('50.0000')
            ->and($row->wht_certificate_reference)->toBeNull();
    });
});

describe('refusals: WHT is validated, nothing is written (AC-WHT-4.1 / 4.2)', function (): void {
    it('refuses a negative WHT amount', function (): void {
        // "A zero line is noise; a negative one would un-pay" — a negative WHT would fabricate a credit.
        $invoice = whtSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '-10.00'),
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and($exception->problemCode())->toBe('receipt-allocation-withholding-negative');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('refuses a WHT amount larger than the allocation it is withheld against', function (): void {
        // WHT ≤ the gross AR it is withheld against (Gate-1 #2) — the readable refusal ahead of the same-row
        // wht_amount <= amount CHECK. Never silently capped, never posted as a negative or over-large line.
        $invoice = whtSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '1100.00'),
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class)
            ->and($exception->problemCode())->toBe('receipt-allocation-withholding-exceeds-allocation');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('refuses a WHT amount finer than the currency precision', function (): void {
        // WHT is a money figure like any other on this receipt and inherits assertAtCurrencyPrecision() — a
        // sub-precision 50.333 in a two-decimal currency is refused before anything posts (AC-WHT-1.5). Reuses
        // the existing amountExceedsCurrencyPrecision refusal; WHT reopens nothing about precision.
        $invoice = whtSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.333'),
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('receipt-amount-exceeds-currency-precision');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });
});

describe('the WHT Receivable account is resolved only when withholding is present', function (): void {
    it('refuses a WHT receipt when the company has no WHT Receivable account', function (): void {
        // The mirror of the invoice map's missing-receivable refusal. Resolved by key; if a company somehow
        // lacks the account, a WHT receipt (Σ wht > 0) cannot post.
        $invoice = whtSuiteInvoice('1000.00');

        DB::table('accounts')->where('company_id', $this->company->getKey())
            ->where('system_key', Account::WHT_RECEIVABLE)->delete();

        $exception = catchPlatformException(fn () => whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '50.00'),
        ], '950.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBePosted::class)
            ->and($exception->problemCode())->toBe('receipt-without-wht-receivable-account');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('still records an ordinary no-WHT receipt when the WHT Receivable account is absent', function (): void {
        // Regression safety (§C): the WHT account is resolved ONLY when Σ wht > 0, so a company lacking it can
        // still record ordinary receipts. WHT is a strictly additive, opt-in debit line.
        $invoice = whtSuiteInvoice('1000.00');

        DB::table('accounts')->where('company_id', $this->company->getKey())
            ->where('system_key', Account::WHT_RECEIVABLE)->delete();

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        expect($receipt->status)->toBe('posted')
            ->and($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Paid);
    });
});

describe('regression: a receipt with no WHT is byte-identical to ADR 0016 (AC-WHT-1.2)', function (): void {
    it('posts the identical two-line entry when fully allocated and no WHT is supplied [regression guard]', function (): void {
        // GREEN FROM THE START. A plain allocation (no WHT field) posts exactly two lines — Dr Bank / Cr AR —
        // with no WHT debit and no Customer Advances line, as receipts did before this wave.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        expect($entry->lines)->toHaveCount(2)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0);
    });

    it('treats an explicit zero WHT exactly as an omitted one', function (): void {
        // AC-WHT-1.4 — an explicit 0 and an omitted field have identical effect: two lines, no WHT debit.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00', whtAmount: '0.00'),
        ], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);

        expect($entry->lines)->toHaveCount(2);
        expect(whtAllocationRowFor((string) $receipt->getKey())->wht_amount)->toBe('0.0000');
    });

    it('still holds a remainder as customer advances with no WHT line [regression guard]', function (): void {
        // GREEN FROM THE START. A plain under-allocated receipt still posts ADR 0016's three lines — Dr Bank /
        // Cr AR / Cr Customer Advances — with no WHT debit. The no-WHT remainder path is untouched.
        $invoice = whtSuiteInvoice('1000.00');

        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '700.00'),
        ], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        expect($entry->lines)->toHaveCount(3)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(700.0)
            ->and(whtHeldCreditFor((string) $receipt->getKey())->original_amount)->toBe('300.0000');
    });
});

describe('receipt_allocations WHT database guards (service bypassed) — Stage 2', function (): void {
    it('refuses a negative wht_amount at the database', function (): void {
        // The backstop under the service refusal: the same-row CHECK (wht_amount >= 0). Exercised by inserting
        // an allocation row directly, bypassing the service — the "prove the database holds" discipline.
        expect(DB::getSchemaBuilder()->hasColumn('receipt_allocations', 'wht_amount'))->toBeTrue();

        $invoice = whtSuiteInvoice('1000.00');
        $other = whtSuiteInvoice('500.00');
        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        expect(fn () => DB::table('receipt_allocations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'sales_invoice_id' => $other->getKey(),
            'amount' => '100.0000',
            'wht_amount' => '-10.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a wht_amount greater than the allocation amount at the database', function (): void {
        // The backstop for "WHT ≤ the gross it is withheld against": the same-row CHECK (wht_amount <= amount),
        // the direct analogue of sales_invoices_amount_paid_not_exceeding_total_check.
        expect(DB::getSchemaBuilder()->hasColumn('receipt_allocations', 'wht_amount'))->toBeTrue();

        $invoice = whtSuiteInvoice('1000.00');
        $other = whtSuiteInvoice('500.00');
        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        expect(fn () => DB::table('receipt_allocations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'sales_invoice_id' => $other->getKey(),
            'amount' => '100.0000',
            'wht_amount' => '200.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('freezes wht_amount via the existing full-freeze trigger, with no trigger change', function (): void {
        // The decisive argument for the allocation-table location (§B): the unconditional
        // asids_receipt_allocations_immutable() already refuses EVERY update, so wht_amount is frozen the
        // instant the row is written — no trigger edit, no omission risk. A plain receipt suffices to prove it.
        expect(DB::getSchemaBuilder()->hasColumn('receipt_allocations', 'wht_amount'))->toBeTrue();

        $invoice = whtSuiteInvoice('1000.00');
        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        $allocationId = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())->value('id');

        expect(fn () => DB::table('receipt_allocations')->where('id', $allocationId)
            ->update(['wht_amount' => '999.0000']))
            ->toThrow(QueryException::class);
    });

    it('keeps a WHT allocation invisible from another tenant [regression guard]', function (): void {
        // RLS is unchanged and correctly so: the WHT columns are on the same isolated table. A second tenant
        // cannot read the allocation carrying them.
        $invoice = whtSuiteInvoice('1000.00');
        $receipt = whtSuiteReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00'),
        ], '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(DB::table('receipt_allocations')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(DB::table('receipt_allocations')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeTrue();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('receipt_allocations'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role (asids_app).'
    );
});
