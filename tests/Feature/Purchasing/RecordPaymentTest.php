<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBePosted;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBeRecorded;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `PaymentService::record()` — the whole record-and-post lifecycle in one step — Stage 4 of Wave 8a (ADR 0020
 * §C4, §F1).
 *
 * The payable-side mirror of `PostBillTest.php`, with two structural differences the born-posted, no-draft
 * lifecycle forces: there is no separate "post an existing draft" call (`record()` does both at once), and a
 * payment may allocate across *several* bills in one transaction, which is the first multi-row lock in this
 * codebase (§F1).
 *
 * No bill is created in `beforeEach` — every test draws its own via `recordBill()` — so the "two number
 * series" test can assert exact `PAY-…0001`/`JV-…0001` values: a bill posted ahead of time in a shared
 * `beforeEach` would itself consume a `JournalVoucher` number and silently shift every count in this file
 * (`BillService::post()` also posts a JV, from the same counter a payment posts to).
 *
 * RED expectation before Stage 4 lands: `PaymentService`, `PaymentData`, `PaymentAllocationData`,
 * `PaymentCannotBeRecorded` and `PaymentCannotBePosted` do not exist, so `app(PaymentService::class)` and the
 * fixtures error every test.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = recordAccount('5100', (string) $this->company->getKey());
    $this->bank = recordAccount('1120', (string) $this->company->getKey());
    $this->tradePayables = recordAccount('2110', (string) $this->company->getKey());

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(
        name: 'Silva Suppliers',
        code: 'SILVA',
    ));

    $this->payments = app(PaymentService::class);
});

function recordAccount(string $code, string $companyId): Account
{
    return Account::query()->forCompany($companyId)->where('code', $code)->firstOrFail();
}

/**
 * A posted, outstanding bill for the given company/supplier — built through the real service so its figures
 * are real. A fresh supplier-invoice-number each call.
 */
function recordBill(Company $company, Supplier $supplier, string $unitPrice = '1000.00'): Bill
{
    static $counter = 0;
    $counter++;

    return app(BillService::class)->post(
        app(BillService::class)->createDraft($company, new BillData(
            supplierId: (string) $supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-REC-'.$counter,
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: $unitPrice,
                expenseAccountId: (string) recordAccount('5100', (string) $company->getKey())->getKey(),
            )],
        )),
        test()->owner,
    );
}

function recordAllocation(string $billId, string $amount): PaymentAllocationData
{
    return new PaymentAllocationData(billId: $billId, amount: $amount);
}

/**
 * @param  list<PaymentAllocationData>  $allocations
 * @param  array<string, mixed>  $overrides
 */
function recordPaymentData(array $allocations, array $overrides = []): PaymentData
{
    $total = array_reduce(
        $allocations,
        static fn (string $carry, PaymentAllocationData $allocation): string => bcadd($carry, $allocation->amount, 4),
        '0.0000',
    );

    return new PaymentData(
        supplierId: $overrides['supplierId'] ?? (string) test()->supplier->getKey(),
        bankAccountId: $overrides['bankAccountId'] ?? (string) test()->bank->getKey(),
        paymentDate: $overrides['paymentDate'] ?? CarbonImmutable::parse('2026-06-15'),
        amount: $overrides['amount'] ?? $total,
        allocations: $allocations,
        reference: $overrides['reference'] ?? null,
        notes: $overrides['notes'] ?? null,
        branchId: $overrides['branchId'] ?? null,
        withholdTax: $overrides['withholdTax'] ?? null,
        whtRateOverride: $overrides['whtRateOverride'] ?? null,
    );
}

describe('recording and posting a payment', function (): void {
    it('creates a payment carrying the supplier, account, date, total and allocations (AC-1.1)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect($payment->supplier_id)->toBe((string) $this->supplier->getKey())
            ->and($payment->bank_account_id)->toBe((string) $this->bank->getKey())
            ->and($payment->payment_date->toDateString())->toBe('2026-06-15')
            ->and($payment->amount)->toBe('1000.0000')
            ->and($payment->allocations)->toHaveCount(1);
    });

    it('is born posted: a number, a posted timestamp and a ledger entry, with no draft stage', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect($payment->status->value)->toBe('posted')
            ->and($payment->number)->toBe('PAY-2026-06-0001')
            ->and($payment->posted_at)->not->toBeNull()
            ->and($payment->journal_entry_id)->not->toBeNull();
    });

    it('records who posted it', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect((string) $payment->posted_by_id)->toBe((string) $this->owner->getKey());
    });

    it('posts Dr Trade Payables = Cr Bank/Cash for the total (AC-2.1)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        expect($entry->lines)->toHaveCount(2)
            ->and((float) $byAccount[$this->tradePayables->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->bank->getKey()]->credit)->toBe(1000.0);
    });

    it('stamps the payment’s branch on both journal lines (ADR §A2)', function (): void {
        // The reporting dimension propagates from the payment header to every ledger line, exactly as a bill's
        // branch does — so a branch P&L or trial balance includes supplier payments (ADR 0020 §A2, Gate-2
        // clarification resolving the §A2/§B divergence in favour of §A2).
        $branch = Branch::factory()->create(['company_id' => $this->company->getKey()]);
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['branchId' => (string) $branch->getKey()]), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);

        expect($entry->lines)->toHaveCount(2)
            ->and($entry->lines->pluck('branch_id')->unique()->all())->toBe([(string) $branch->getKey()]);
    });

    it('leaves the journal lines’ branch null when the payment names no branch', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);

        expect($entry->lines->pluck('branch_id')->unique()->all())->toBe([null]);
    });

    it('records a caller-supplied external reference, and lets two payments share one (AC-3.2, dec. 7)', function (): void {
        // A reference is optional and NOT unique — a bank txn id or cheque number, with duplicate detection
        // deliberately left to audit (Gate-1 dec. 7). Both are exercised here through the real service.
        $billA = recordBill($this->company, $this->supplier, '1000.00');
        $billB = recordBill($this->company, $this->supplier, '1000.00');

        $first = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billA->getKey(), '1000.00'),
        ], ['reference' => 'CHQ-000123']), $this->owner);

        $second = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billB->getKey(), '1000.00'),
        ], ['reference' => 'CHQ-000123']), $this->owner);

        expect($first->reference)->toBe('CHQ-000123')
            ->and($second->reference)->toBe('CHQ-000123')
            ->and(SupplierPayment::query()->where('reference', 'CHQ-000123')->count())->toBe(2);
    });

    it('numbers the journal entry from the journal voucher series, not the payment series', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);

        // JV-0002, not 0001: `recordBill()` posts a real bill through `BillService::post()`, whose own ledger
        // entry is typed `JournalVoucher` and draws JV-0001 first (proven by `PostBillTest`). The payment's
        // entry is the second JV in the period. The point of this test is the *series* — `JV-`, not `PAY-`,
        // and `document_type` JournalVoucher — not the ordinal.
        expect($entry->number)->toBe('JV-2026-06-0002')
            ->and($entry->document_type)->toBe(DocumentType::JournalVoucher);
    });

    it('records the payment as the entry’s source document', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);

        expect($entry->source_type)->toBe('supplier_payment')
            ->and($entry->source_id)->toBe((string) $payment->getKey())
            ->and($entry->reference)->toBe($payment->number);
    });

    it('moves amount_paid up and amount_due down, flipping a fully-cleared bill to Paid (AC-2.2, AC-2.3)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $fresh = $bill->fresh();

        expect($fresh->amount_paid)->toBe('1000.0000')
            ->and($fresh->amount_due)->toBe('0.0000')
            ->and($fresh->status->value)->toBe('paid');
    });

    it('flips a partially-cleared bill to PartiallyPaid (AC-2.3)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '400.00'),
        ]), $this->owner);

        $fresh = $bill->fresh();

        expect($fresh->amount_paid)->toBe('400.0000')
            ->and($fresh->amount_due)->toBe('600.0000')
            ->and($fresh->status->value)->toBe('partially_paid');
    });

    it('moves Posted -> PartiallyPaid -> Paid across two sequential payments against the same bill', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '400.00'),
        ]), $this->owner);

        expect($bill->fresh()->status->value)->toBe('partially_paid');

        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '600.00'),
        ]), $this->owner);

        expect($bill->fresh()->status->value)->toBe('paid')
            ->and($bill->fresh()->amount_due)->toBe('0.0000');
    });

    it('drops the supplier’s outstanding balance by exactly what was allocated (AC-2.4)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '400.00'),
        ]), $this->owner);

        $outstanding = Bill::query()
            ->forCompany($this->company->getKey())
            ->where('supplier_id', $this->supplier->getKey())
            ->outstanding()
            ->sum('amount_due');

        expect(bcadd((string) $outstanding, '0', 4))->toBe('600.0000');
    });

    it('allocates across several bills in one payment, moving each independently', function (): void {
        $billA = recordBill($this->company, $this->supplier, '600.00');
        $billB = recordBill($this->company, $this->supplier, '900.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billA->getKey(), '600.00'),
            recordAllocation((string) $billB->getKey(), '400.00'),
        ]), $this->owner);

        expect($payment->amount)->toBe('1000.0000')
            ->and($payment->allocations)->toHaveCount(2)
            ->and($billA->fresh()->status->value)->toBe('paid')
            ->and($billA->fresh()->amount_due)->toBe('0.0000')
            ->and($billB->fresh()->status->value)->toBe('partially_paid')
            ->and($billB->fresh()->amount_due)->toBe('500.0000');
    });
});

describe('the two number series (ADR 0009 §B — a single-document test passes either way)', function (): void {
    it('advances PAY and JV independently across several payments', function (): void {
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $bill = recordBill($this->company, $this->supplier, '1000.00');
            $payment = $this->payments->record($this->company, recordPaymentData([
                recordAllocation((string) $bill->getKey(), '1000.00'),
            ]), $this->owner);
            $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);

            $numbers[] = [$payment->number, $entry->number];
        }

        // Every single-payment assertion above passes whether or not the two series share a counter; only a
        // sequence of several shows a shared-counter defect (the ADR 0009 §B trap). Each iteration posts a
        // bill (drawing one JV) then a payment (drawing the next JV), so the payment JVs are the even ones —
        // 0002, 0004, 0006 — while PAY stays 0001, 0002, 0003 on its own counter. That divergence is exactly
        // what proves the two series are independent: a shared counter would have gapped PAY.
        expect($numbers)->toBe([
            ['PAY-2026-06-0001', 'JV-2026-06-0002'],
            ['PAY-2026-06-0002', 'JV-2026-06-0004'],
            ['PAY-2026-06-0003', 'JV-2026-06-0006'],
        ]);
    });

    it('keeps the two counters independent in the sequence table, and gapless for PAY', function (): void {
        for ($i = 0; $i < 3; $i++) {
            $bill = recordBill($this->company, $this->supplier, '1000.00');
            $this->payments->record($this->company, recordPaymentData([
                recordAllocation((string) $bill->getKey(), '1000.00'),
            ]), $this->owner);
        }

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->where('period_key', '2026-06')
            ->pluck('next_number', 'document_type');

        // PAY advanced 3 times (next 4). The JV counter advanced 6 times (next 7): three bill posts plus
        // three payment posts, each drawing one JV — the two counters move at different rates, which is the
        // independence the test exists to prove.
        expect((int) $sequences[DocumentType::Payment->value])->toBe(4)
            ->and((int) $sequences[DocumentType::JournalVoucher->value])->toBe(7);

        // Gapless (dec. 4): every number the sequence issued is actually carried by a payment row — no hole.
        $numbers = SupplierPayment::query()->pluck('number')->sort()->values()->all();
        expect($numbers)->toBe(['PAY-2026-06-0001', 'PAY-2026-06-0002', 'PAY-2026-06-0003']);
    });
});

describe('what recording refuses', function (): void {
    it('refuses no allocations at all (AC-1.2)', function (): void {
        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([], ['amount' => '100.00'])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class);
    });

    it('refuses an allocation naming a bill that does not belong to the named supplier (AC-1.3)', function (): void {
        $otherSupplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Other Co', code: 'OTHER'));
        $bill = recordBill($this->company, $otherSupplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-bill-not-for-supplier');
    });

    it('refuses an allocation naming a bill that is not outstanding — a draft (AC-1.4)', function (): void {
        $draft = app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PAY-REC-DRAFT',
            lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '1000.00', expenseAccountId: (string) $this->purchases->getKey())],
        ));

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $draft->getKey(), '1000.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-bill-not-outstanding');
    });

    it('refuses an allocation naming a bill that is already Paid (AC-1.4)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');
        $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1.00'),
        ])));

        expect($exception->problemCode())->toBe('payment-bill-not-outstanding');
    });

    it('refuses an allocation naming a cancelled bill (AC-1.4)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');
        DB::table('bills')->where('id', $bill->getKey())->update(['status' => 'cancelled', 'updated_at' => now()]);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ])));

        expect($exception->problemCode())->toBe('payment-bill-not-outstanding');
    });

    it('refuses an allocation naming a bill belonging to a different company (AC-1.5)', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::parse('2026-06-15'));
        $secondSupplier = app(SupplierService::class)->create($second, new SupplierData(name: 'Second Supplier', code: 'SEC'));
        $siblingBill = recordBill($second, $secondSupplier, '1000.00');

        // $this->supplier (Acme's own) stays the payment's supplier — only the allocation strays cross-company,
        // isolating `billOutsideCompany` rather than `billNotForSupplier` (both would technically be true of
        // this row; the ADR's own check order puts company-membership first).
        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $siblingBill->getKey(), '1000.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-bill-outside-company');
    });

    it('refuses an allocation greater than the bill’s current amount_due (AC-1.6, the pre-check)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.01'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-allocation-exceeds-due');
    });

    it('refuses when the sum of allocations does not equal the stated total (AC-1.7)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '400.00'),
        ], ['amount' => '1000.00'])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-not-fully-allocated');
    });

    it('refuses a payment amount of zero or less (AC-1.8)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '0.00'),
        ], ['amount' => '0.00'])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class);
    });

    it('refuses a non-positive allocation amount even when the header total happens to be positive', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '0.00'),
        ], ['amount' => '500.00'])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class);
    });

    it('refuses the same bill named twice in one payload', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '500.00'),
            recordAllocation((string) $bill->getKey(), '500.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-duplicate-bill-allocation');
    });

    it('refuses a supplier belonging to a different company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $secondSupplier = app(SupplierService::class)->create($second, new SupplierData(name: 'Second Supplier', code: 'SEC'));

        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $secondSupplier->getKey()])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-supplier-outside-company');
    });

    it('refuses a bank account belonging to a different company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $secondBank = recordAccount('1120', (string) $second->getKey());

        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['bankAccountId' => (string) $secondBank->getKey()])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-bank-account-outside-company');
    });

    it('refuses a branch belonging to a different company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $secondBranch = Branch::factory()->create(['company_id' => $second->getKey()]);

        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['branchId' => (string) $secondBranch->getKey()])));

        // Mirrors `resolveBranchId` (`BillService.php:587-606`) directly — the ADR names no new
        // `PaymentCannotBeRecorded` factory for this step (§C4 step 3), so the already-existing generic code
        // is expected, not a new payment-prefixed one. Flagged in the QA report as an interpretation.
        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('branch-outside-company');
    });

    it('refuses via the posting map when the bank account is not postable, surfacing PaymentCannotBePosted', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        DB::table('accounts')->where('id', $this->bank->getKey())->update(['is_active' => false, 'archived_at' => now()]);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBePosted::class);
    });

    it('refuses a payment dated in a closed period, before any number is reserved (AC-2.7)', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-15'))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ])));

        expect($exception)->toBeInstanceOf(PaymentCannotBePosted::class);

        expect(DB::table('document_sequences')->where('document_type', DocumentType::Payment->value)->count())->toBe(0);
    });
});

describe('AC-1.10 — the deliberate divergence: an archived or dormant supplier is still payable', function (): void {
    it('lets a dormant (deactivated) supplier’s existing bill still be paid', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        app(SupplierService::class)->deactivate($this->supplier);

        // record() must NOT call acceptsNewBills() — only new billing is refused for a dormant supplier, not
        // paying what is already owed.
        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect($payment->status->value)->toBe('posted')
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('lets an archived supplier’s existing bill still be paid', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        // `SupplierService::archive()` itself refuses while a balance is outstanding — exactly the situation
        // this test needs to reach, so the state is forced with raw SQL, bypassing that guard on purpose to
        // reach the state the ADR describes (an archived supplier who is nonetheless still owed money).
        DB::table('suppliers')->where('id', $this->supplier->getKey())->update(['status' => 'archived', 'archived_at' => now()]);

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect($payment->status->value)->toBe('posted')
            ->and($bill->fresh()->status->value)->toBe('paid');
    });
});

describe('source_id uniqueness blocks a second post (AC-2.8)', function (): void {
    it('is stopped by the database even when PaymentService is bypassed entirely', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        // The concurrency case, reproduced deterministically: a second entry citing the same source document
        // (this payment) is refused by the unique index over `journal_entries.source_id` — the authority the
        // application cannot see, mirroring `PostBillTest`'s equivalent proof.
        $secondPosting = fn () => app(PostingService::class)->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'A racing second posting of the same payment',
            lines: [
                new JournalLineData(accountId: (string) $this->tradePayables->getKey(), debit: Money::of('1000.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->bank->getKey(), credit: Money::of('1000.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
            source: SourceDocument::for($payment),
        ), $this->owner);

        expect($secondPosting)->toThrow(QueryException::class);

        expect(JournalEntry::query()->where('source_id', (string) $payment->getKey())->count())->toBe(1);
    });
});

describe('a failed record leaves nothing behind', function (): void {
    it('consumes no PAY or JV number, posts nothing, and moves no bill', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        // Baseline AFTER the bill posts: the bill legitimately created one journal entry and advanced the JV
        // counter. The failed payment must leave both exactly here — it is the *payment* that leaves nothing
        // behind, not the whole database.
        $journalEntriesBefore = JournalEntry::query()->count();
        $jvNextBefore = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->where('document_type', DocumentType::JournalVoucher->value)
            ->value('next_number');

        DB::table('accounts')->where('id', $this->bank->getKey())->update(['is_active' => false, 'archived_at' => now()]);

        catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ])));

        // No payment row, no PAY number ever reserved (it fails before the transaction opens), and neither the
        // JV counter nor the ledger moved beyond the bill's own posting.
        expect(DB::table('supplier_payments')->count())->toBe(0)
            ->and(DB::table('document_sequences')->where('document_type', DocumentType::Payment->value)->count())->toBe(0)
            ->and(JournalEntry::query()->count())->toBe($journalEntriesBefore)
            ->and(DB::table('document_sequences')
                ->where('company_id', $this->company->getKey())
                ->where('document_type', DocumentType::JournalVoucher->value)
                ->value('next_number'))->toBe($jvNextBefore);

        $fresh = $bill->fresh();
        expect($fresh->amount_paid)->toBe('0.0000')
            ->and($fresh->amount_due)->toBe('1000.0000')
            ->and($fresh->status->value)->toBe('posted');
    });
});

/**
 * A withholding supplier at the given rate.
 */
function whtSupplier(string $rate = '5.00'): Supplier
{
    return app(SupplierService::class)->create(test()->company, new SupplierData(
        name: 'WHT Supplier '.$rate,
        code: 'WHT-'.str_replace('.', '', $rate),
        whtApplicable: true,
        whtRate: $rate,
    ));
}

describe('withholding tax on a payment (ADR 0021)', function (): void {
    it('withholds at the supplier rate — 950 cash + 50 WHT settles a 1,000 bill in full (worked example, AC-1.1)', function (): void {
        $supplier = whtSupplier('5.00');
        $bill = recordBill($this->company, $supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('50.0000')
            ->and($payment->wht_rate)->toBe('5.00')
            ->and($payment->amount)->toBe('1000.0000');

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');
        $whtPayable = recordAccount('2180', (string) $this->company->getKey());

        expect($entry->lines)->toHaveCount(3)
            ->and((float) $byAccount[$this->tradePayables->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->bank->getKey()]->credit)->toBe(950.0)
            ->and((float) $byAccount[$whtPayable->getKey()]->credit)->toBe(50.0);

        // Gross settlement (dec. 3): the bill moves by the full 1,000 and is Paid.
        expect($bill->fresh()->amount_paid)->toBe('1000.0000')
            ->and($bill->fresh()->amount_due)->toBe('0.0000')
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('withholds on the pre-VAT service value, not the VAT-inclusive total (dec. 2)', function (): void {
        $supplier = whtSupplier('5.00');

        // A VAT code with an input account, so the bill can post (AC-3.7).
        $outputVat = recordAccount('2140', (string) $this->company->getKey());
        $inputVat = recordAccount('1170', (string) $this->company->getKey());
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $outputVat->getKey(),
            inputAccountId: (string) $inputVat->getKey(),
        ));

        // net 1,000 + 18% VAT = 1,180 total.
        $bill = app(BillService::class)->post(
            app(BillService::class)->createDraft($this->company, new BillData(
                supplierId: (string) $supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'VAT-WHT-1',
                lines: [new BillLineData(
                    description: 'Consulting',
                    quantity: '1',
                    unitPrice: '1000.00',
                    expenseAccountId: (string) $this->purchases->getKey(),
                    taxCode: 'VAT',
                )],
            )),
            $this->owner,
        );

        expect($bill->total)->toBe('1180.0000')->and($bill->subtotal)->toBe('1000.0000');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1180.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        // 5% of the pre-VAT 1,000 = 50, NOT 5% of 1,180 (= 59). Net cash = 1,180 − 50 = 1,130.
        expect($payment->wht_amount)->toBe('50.0000');

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');
        expect((float) $byAccount[$this->bank->getKey()]->credit)->toBe(1130.0);
    });

    it('withholds proportionally on a partial allocation', function (): void {
        $supplier = whtSupplier('5.00');
        $bill = recordBill($this->company, $supplier, '1000.00');   // VAT-free, subtotal == total == 1000

        // Pay only 400 of the 1,000 bill. Pre-VAT base = 400 × 1000/1000 = 400; WHT = 5% × 400 = 20.
        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '400.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('20.0000')
            ->and($bill->fresh()->amount_paid)->toBe('400.0000')
            ->and($bill->fresh()->status->value)->toBe('partially_paid');
    });

    it('applies the supplier default with no explicit instruction', function (): void {
        $supplier = whtSupplier('10.00');
        $bill = recordBill($this->company, $supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('100.0000')
            ->and($payment->wht_rate)->toBe('10.00');
    });

    it('honours a per-payment rate override', function (): void {
        $supplier = whtSupplier('5.00');
        $bill = recordBill($this->company, $supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey(), 'whtRateOverride' => '14']), $this->owner);

        expect($payment->wht_amount)->toBe('140.0000')
            // Snapshotted through the decimal:2 cast.
            ->and($payment->wht_rate)->toBe('14.00');
    });

    it('suppresses withholding for one payment when the caller says so', function (): void {
        $supplier = whtSupplier('5.00');
        $bill = recordBill($this->company, $supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey(), 'withholdTax' => false]), $this->owner);

        // No WHT: byte-for-byte the 8a two-line entry.
        expect($payment->wht_amount)->toBe('0.0000')
            ->and($payment->wht_rate)->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        expect($entry->lines)->toHaveCount(2);
    });

    it('withholds one-off from a non-withholding supplier when explicitly told to', function (): void {
        // The beforeEach supplier is non-withholding.
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['withholdTax' => true, 'whtRateOverride' => '5']), $this->owner);

        expect($payment->wht_amount)->toBe('50.0000');
    });

    it('a non-withholding supplier posts the exact 8a two-line entry with zero WHT', function (): void {
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ]), $this->owner);

        expect($payment->wht_amount)->toBe('0.0000')
            ->and($payment->wht_rate)->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        expect($entry->lines)->toHaveCount(2);
    });

    it('refuses an out-of-range override rate', function (): void {
        $supplier = whtSupplier('5.00');
        $bill = recordBill($this->company, $supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey(), 'whtRateOverride' => '150'])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-wht-rate-out-of-range');
    });

    it('refuses to withhold with no rate anywhere', function (): void {
        // Non-withholding supplier forced on, but no override supplied.
        $bill = recordBill($this->company, $this->supplier, '1000.00');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '1000.00'),
        ], ['withholdTax' => true])));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-wht-rate-missing');
    });

    it('rounds the withholding ONCE across several bills (dec. 5 / fork 4)', function (): void {
        // The regression guard the single-bill tests cannot provide: with two bills 33.33 + 33.34 at 5%,
        // round-once (implemented) gives round(5% × 66.67) = round(3.3335) = 3.33; round-per-bill (the rejected
        // alternative) would give round(1.6665) + round(1.6670) = 1.67 + 1.67 = 3.34. Only a multi-bill
        // payment distinguishes them, and Gate 2 chose round-once.
        $supplier = whtSupplier('5.00');
        $billA = recordBill($this->company, $supplier, '33.33');
        $billB = recordBill($this->company, $supplier, '33.34');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billA->getKey(), '33.33'),
            recordAllocation((string) $billB->getKey(), '33.34'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('3.3300');
    });

    it('withholds proportionally on a partial allocation of a VAT bill (pre-VAT base + partial together)', function (): void {
        $supplier = whtSupplier('5.00');

        $outputVat = recordAccount('2140', (string) $this->company->getKey());
        $inputVat = recordAccount('1170', (string) $this->company->getKey());
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $outputVat->getKey(),
            inputAccountId: (string) $inputVat->getKey(),
        ));

        // net 1,000 + 18% VAT = 1,180 total.
        $bill = app(BillService::class)->post(
            app(BillService::class)->createDraft($this->company, new BillData(
                supplierId: (string) $supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'VAT-WHT-PARTIAL',
                lines: [new BillLineData(
                    description: 'Consulting',
                    quantity: '1',
                    unitPrice: '1000.00',
                    expenseAccountId: (string) $this->purchases->getKey(),
                    taxCode: 'VAT',
                )],
            )),
            $this->owner,
        );

        // Pay 590 of the 1,180 bill (half). Pre-VAT base = 590 × 1000/1180 = 500; WHT = 5% × 500 = 25.
        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '590.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('25.0000')
            ->and($bill->fresh()->status->value)->toBe('partially_paid');
    });

    it('posts a two-line net-zero entry for a 100%-withheld payment through the service (no bank line)', function (): void {
        $supplier = whtSupplier('100.00');
        $bill = recordBill($this->company, $supplier, '500.00');

        $payment = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $bill->getKey(), '500.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        expect($payment->wht_amount)->toBe('500.0000');

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $whtPayable = recordAccount('2180', (string) $this->company->getKey());

        // Dr Trade Payables 500 = Cr WHT Payable 500 — no bank line (net is zero).
        expect($entry->lines)->toHaveCount(2)
            ->and($entry->lines->pluck('account_id')->all())->not->toContain((string) $this->bank->getKey())
            ->and($entry->lines->firstWhere('account_id', (string) $whtPayable->getKey())->credit)->not->toBeNull()
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('accumulates WHT Payable across several withholding payments (AC-1.6)', function (): void {
        $supplier = whtSupplier('5.00');
        $billA = recordBill($this->company, $supplier, '1000.00');
        $billB = recordBill($this->company, $supplier, '2000.00');

        $paymentA = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billA->getKey(), '1000.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);
        $paymentB = $this->payments->record($this->company, recordPaymentData([
            recordAllocation((string) $billB->getKey(), '2000.00'),
        ], ['supplierId' => (string) $supplier->getKey()]), $this->owner);

        // WHT Payable is a live liability: 50 + 100 = 150 credited across the two payments' entries.
        $whtPayable = recordAccount('2180', (string) $this->company->getKey());
        $credited = JournalLine::query()
            ->where('account_id', $whtPayable->getKey())
            ->sum('credit');

        expect(bcadd((string) $credited, '0', 4))->toBe('150.0000')
            ->and($paymentA->wht_amount)->toBe('50.0000')
            ->and($paymentB->wht_amount)->toBe('100.0000');
    });
});
