<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptPostingMap;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC-CR-2.2 coverage — the differing-receivable-account refusal survives a remainder (ADR 0016 §C).
 *
 * Added by QA at the Stage 5 independent verification gate, closing a named-acceptance-criterion coverage gap.
 *
 * ADR 0016 §C makes the receipt posting VARIABLE-LINE: the allocated portion credits the single Trade
 * Receivables account resolved across the named invoices, and — only when a remainder is left — a SEPARATE
 * Customer Advances line credits it. AC-CR-2.2 requires that the pre-existing "allocations span more than one
 * receivable account → refuse rather than mis-post" invariant (ADR 0014 AC-3.2) is UNAFFECTED when the receipt
 * also carries a remainder, and that the two resolutions are never conflated: the receivable resolution must
 * refuse BEFORE any Customer Advances line is emitted.
 *
 * The existing AC-3.2 test (`RecordReceiptTest`) proves the refusal only for a FULLY-ALLOCATED (no-remainder)
 * receipt. This file adds the remainder-present variant AC-CR-2.2 names explicitly, which no other test
 * exercised. `ReceiptPostingMap` is a documented pure map — it reads, resolves accounts, and posts nothing — so
 * it is exercised directly on a hand-built receipt, exactly as the AC-3.2 test does, because `record()` refuses
 * a cross-customer allocation earlier and so cannot construct two-receivable-account input.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = ac22Account('4100');
    $this->receivables = ac22Account('1130');       // system Trade Receivables
    $this->otherReceivables = ac22Account('1140');  // a second receivable-family account, used as an override
    $this->bank = ac22Account('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function ac22Account(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

function ac22Invoice(string $unitPrice, string $customerId): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: $customerId,
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

it('refuses a remainder receipt whose allocations span two receivable accounts, emitting no advances line', function (): void {
    // A second customer overrides its receivable account to 1140; the suite customer resolves to system 1130.
    $customerB = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Overridden Ltd',
        code: 'OVERRIDE',
        receivableAccountId: (string) $this->otherReceivables->getKey(),
    ));

    $invoiceA = ac22Invoice('500.00', (string) $this->customer->getKey());  // resolves to 1130
    $invoiceB = ac22Invoice('500.00', (string) $customerB->getKey());       // resolves to 1140

    // Issuing the two setup invoices already posted their own entries; the invariant is that the MAP posts
    // nothing beyond them, so the baseline is taken after issuance.
    $entriesBefore = JournalEntry::query()->count();

    // Amount 1200 over Σ allocations 1000 => a 200 remainder. The remainder is precisely what makes this the
    // AC-CR-2.2 case rather than the already-covered AC-3.2 (no-remainder) one. Built directly because a
    // two-receivable-account, single-receipt allocation is unreachable through the validated service path.
    $receipt = new CustomerReceipt;
    $receipt->company_id = $this->company->getKey();
    $receipt->customer_id = $this->customer->getKey();
    $receipt->number = 'RCT-AC22-0001';
    $receipt->receipt_date = CarbonImmutable::parse('2026-06-20');
    $receipt->currency_code = $this->company->base_currency_code;
    $receipt->amount = '1200.0000';
    $receipt->payment_method = PaymentMethod::BankTransfer->value;
    $receipt->bank_account_id = $this->bank->getKey();
    $receipt->status = 'posted';
    $receipt->save();

    DB::table('receipt_allocations')->insert([
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'sales_invoice_id' => $invoiceA->getKey(),
            'amount' => '500.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'sales_invoice_id' => $invoiceB->getKey(),
            'amount' => '500.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Guard the guard: this receipt genuinely leaves a positive remainder, so the test exercises the
    // remainder-present path AC-CR-2.2 is about — not the zero-remainder path AC-3.2 already covers.
    $allocated = (float) DB::table('receipt_allocations')
        ->where('customer_receipt_id', $receipt->getKey())->sum('amount');
    expect((float) $receipt->fresh()->amount - $allocated)->toBe(200.0);

    // The differing-receivable-account refusal fires. It is thrown while resolving the allocated portion's
    // control account, before the variable-line map reaches the remainder branch — so a Customer Advances line
    // is never emitted and nothing is posted on either account.
    $exception = catchPlatformException(fn () => app(ReceiptPostingMap::class)->for($receipt->fresh()));

    expect($exception)->toBeInstanceOf(ReceiptCannotBePosted::class)
        ->and($exception->problemCode())->toBe('receipt-receivable-accounts-differ');

    expect(JournalEntry::query()->count())->toBe($entriesBefore);
});
