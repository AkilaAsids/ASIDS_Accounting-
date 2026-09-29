<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBeCancelled;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `PaymentService::cancel()` — reverse a posted payment and restore the bills it settled (Wave 8c, ADR 0023).
 *
 * The payable-side mirror of `CancelInvoiceTest`. A payment is a statutory record, so cancelling reverses rather
 * than deletes: a mirror journal entry undoes the posting, each settled bill returns to what it owed, the
 * allocations survive as history, and the payment moves to `cancelled` with its reason/date/actor. Foreign and
 * withholding payments are cancellable (dec. D7): the reversal undoes realised FX and the WHT credit too.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];   // base LKR, precision 2

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = cpAccount('5100');
    $this->bank = cpAccount('1120');
    $this->tradePayables = cpAccount('2110');
    $this->whtPayable = cpAccount('2180');
    $this->fx = cpAccount('4910');

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));
    $this->bills = app(BillService::class);
    $this->payments = app(PaymentService::class);
});

function cpAccount(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * A posted, outstanding bill built through the real service. Fresh supplier-invoice-number each call.
 */
function cpBill(string $unitPrice = '1000.00', ?string $currency = null, ?string $rate = null, ?Supplier $supplier = null): Bill
{
    static $counter = 0;
    $counter++;

    $supplier ??= test()->supplier;

    return test()->bills->post(
        test()->bills->createDraft(test()->company, new BillData(
            supplierId: (string) $supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'CP-'.$counter,
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: $unitPrice,
                expenseAccountId: (string) test()->purchases->getKey(),
            )],
            currencyCode: $currency,
            exchangeRate: $rate,
        )),
        test()->owner,
    );
}

/**
 * @return array<string, JournalLine> lines keyed by account_id
 */
function cpEntryLines(string $journalEntryId)
{
    return JournalEntry::query()->with('lines')->findOrFail($journalEntryId)->lines->keyBy('account_id');
}

describe('cancelling a base-currency payment (ADR 0023 §B)', function (): void {
    it('reverses the ledger entry, restores the bill, and records the cancellation', function (): void {
        $bill = cpBill('1000.00');   // payable 1000
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        expect($bill->fresh()->status)->toBe(BillStatus::Paid);

        $cancelled = $this->payments->cancel($payment, 'recorded in error', $this->owner);

        // The payment is cancelled, carrying its metadata; its figures and number survive.
        expect($cancelled->status)->toBe(PaymentStatus::Cancelled)
            ->and($cancelled->cancellation_reason)->toBe('recorded in error')
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->cancelled_by_id)->toBe((string) $this->owner->getKey())
            ->and($cancelled->amount)->toBe('1000.0000')
            ->and($cancelled->number)->toBe($payment->number);

        // The bill is restored to outstanding.
        $freshBill = $bill->fresh();
        expect($freshBill->status)->toBe(BillStatus::Posted)
            ->and($freshBill->amount_paid)->toBe('0.0000')
            ->and($freshBill->amount_due)->toBe('1000.0000');

        // The original entry is reversed and a mirror entry nets it to zero.
        $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        expect($original->status)->toBe(JournalEntryStatus::Reversed)
            ->and($original->reversed_by_entry_id)->not->toBeNull();

        $reversal = cpEntryLines((string) $original->reversed_by_entry_id);
        // Original was Dr Trade Payables 1000 = Cr Bank 1000; the reversal swaps them.
        expect((float) $reversal[$this->tradePayables->getKey()]->credit)->toBe(1000.0)
            ->and((float) $reversal[$this->bank->getKey()]->debit)->toBe(1000.0);
    });

    it('retains the allocations as history', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        $this->payments->cancel($payment, 'recorded in error', $this->owner);

        expect(DB::table('payment_allocations')->where('supplier_payment_id', $payment->getKey())->count())->toBe(1);
    });

    it('restores a partially-paying payment and leaves the bill outstanding at the right balance', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '400.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '400.00')],
        ), $this->owner);

        expect($bill->fresh()->status)->toBe(BillStatus::PartiallyPaid);

        $this->payments->cancel($payment, 'oops', $this->owner);

        $fresh = $bill->fresh();
        expect($fresh->status)->toBe(BillStatus::Posted)
            ->and($fresh->amount_paid)->toBe('0.0000')
            ->and($fresh->amount_due)->toBe('1000.0000');
    });

    it('restores only its own contribution to a bill also paid by another payment', function (): void {
        $bill = cpBill('1000.00');
        $mk = fn (string $amount) => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: $amount,
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: $amount)],
        ), $this->owner);

        $first = $mk('400.00');
        $mk('600.00');   // now fully paid
        expect($bill->fresh()->status)->toBe(BillStatus::Paid);

        // Cancelling the first payment restores 400, leaving the bill partially paid at 600.
        $this->payments->cancel($first, 'first was wrong', $this->owner);

        $fresh = $bill->fresh();
        expect($fresh->status)->toBe(BillStatus::PartiallyPaid)
            ->and($fresh->amount_paid)->toBe('600.0000')
            ->and($fresh->amount_due)->toBe('400.0000');
    });
});

describe('cancelling a withholding payment (ADR 0023 §D4)', function (): void {
    it('reverses the WHT Payable credit as well as cash and payable', function (): void {
        // A supplier that withholds 10%.
        $supplier = app(SupplierService::class)->create($this->company, new SupplierData(
            name: 'Withholding Co', code: 'WHTCO', whtApplicable: true, whtRate: '10.00',
        ));
        $bill = cpBill('1000.00', supplier: $supplier);

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        expect((float) $payment->wht_amount)->toBe(100.0);

        $this->payments->cancel($payment, 'recorded in error', $this->owner);

        $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $reversal = cpEntryLines((string) $original->reversed_by_entry_id);

        // Original: Dr TP 1000 = Cr Bank 900 + Cr WHT 100. Reversal swaps every side.
        expect((float) $reversal[$this->tradePayables->getKey()]->credit)->toBe(1000.0)
            ->and((float) $reversal[$this->bank->getKey()]->debit)->toBe(900.0)
            ->and((float) $reversal[$this->whtPayable->getKey()]->debit)->toBe(100.0);
    });
});

describe('cancelling a foreign payment (ADR 0023 §D)', function (): void {
    it('reverses the realised FX, nets the base entry to zero, and restores the foreign amount_due', function (): void {
        $bill = cpBill('1000.00', 'USD', '300');   // payable USD 1000, base 300,000
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',   // FX loss of 10,000 on settlement
        ), $this->owner);

        $this->payments->cancel($payment, 'wrong rate', $this->owner);

        $original = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $reversal = cpEntryLines((string) $original->reversed_by_entry_id);

        // The reversal base-nets to zero (it is a mirror), and the FX line is reversed (was Dr 10,000 → Cr).
        $debits = collect($reversal)->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->debit, 4), '0');
        $credits = collect($reversal)->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->credit, 4), '0');
        expect(bccomp($debits, $credits, 2))->toBe(0)
            ->and((float) $reversal[$this->fx->getKey()]->credit)->toBe(10000.0);

        // The Trade Payables reversal carries the transaction overlay (§D).
        $tp = $reversal[$this->tradePayables->getKey()];
        expect($tp->transaction_currency_code)->toBe('USD')
            ->and((float) $tp->transaction_amount)->toBe(1000.0);

        // The USD bill is fully restored in its own currency.
        $fresh = $bill->fresh();
        expect($fresh->status)->toBe(BillStatus::Posted)
            ->and($fresh->amount_due)->toBe('1000.0000');
    });
});

describe('the cancellation guards (ADR 0023 §B, §F)', function (): void {
    it('refuses to cancel an already-cancelled payment', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        $this->payments->cancel($payment, 'recorded in error', $this->owner);

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment->fresh(), 'again', $this->owner));
        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('payment-already-cancelled');
    });

    it('refuses a blank reason', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment, '   ', $this->owner));
        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('cancellation-reason-required');

        // Nothing changed — still posted, bill still paid.
        expect($payment->fresh()->status)->toBe(PaymentStatus::Posted)
            ->and($bill->fresh()->status)->toBe(BillStatus::Paid);
    });

    it('refuses a reason longer than the ledger can store, and writes nothing', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment, str_repeat('x', 256), $this->owner));
        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('cancellation-reason-too-long');

        expect($payment->fresh()->status)->toBe(PaymentStatus::Posted)
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });

    it('refuses when the period the reversal would land in is closed, and writes nothing (D1)', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        // The reversal posts into TODAY's period, not the payment's — so it is today's period the guard checks
        // (Gate-1 dec. D1). Close it directly (closed_at moves with the status per fiscal_periods_closed_check).
        FiscalPeriod::query()->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::now())
            ->update(['status' => PeriodStatus::Closed->value, 'closed_at' => now()]);

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment, 'too late', $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('payment-cancel-period-not-open')
            // The refusal cost nothing (R4): no reversal written, payment still posted, bill still paid.
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Posted)
            ->and($bill->fresh()->status)->toBe(BillStatus::Paid);
    });

    it('refuses when the payment’s ledger entry belongs to a different company', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        // A sibling company in the same workspace. RLS is satisfied by either (shared tenant), so only the
        // service's explicit company comparison refuses a reversal that would land in the sibling's ledger.
        $sibling = app(CompanyService::class)->create(new CreateCompanyData(name: 'Sibling Books'), $this->owner);

        // The entry's company is frozen by its immutability trigger; disable it only to plant this cross-company
        // state the service must then refuse (the entry is a posted record, so there is no legitimate path here).
        DB::statement('ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_immutable');
        try {
            DB::table('journal_entries')->where('id', $payment->journal_entry_id)
                ->update(['company_id' => $sibling->getKey()]);
        } finally {
            DB::statement('ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_immutable');
        }

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment->fresh(), 'wrong company', $this->owner));
        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('payment-outside-company');
    });

    it('refuses when the ledger entry has already been reversed', function (): void {
        $bill = cpBill('1000.00');
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
        ), $this->owner);

        // Reversed directly in Accounting, leaving the payment still marked posted — the state a partially
        // applied cancellation would leave behind.
        app(PostingService::class)->reverse(
            JournalEntry::query()->findOrFail($payment->journal_entry_id),
            'reversed outside purchasing',
            CarbonImmutable::parse('2026-06-20'),
            $this->owner,
        );

        $exception = catchPlatformException(fn () => $this->payments->cancel($payment->fresh(), 'cancel too', $this->owner));
        expect($exception)->toBeInstanceOf(PaymentCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('payment-cancel-not-reversible');
    });
});

describe('the database authority behind bill restoration (AC-2.1)', function (): void {
    // True OS-level concurrency is not reproducible in this single-process RefreshDatabase harness (the same
    // documented limitation as PaymentAllocationRaceTest). What carries the AC-2.1 "never negative" guarantee is
    // the database CHECK the service's restoration is layered over: a restore can only reduce amount_paid, and
    // the DB refuses any value below zero — so even a lost update or a double-cancel bug could not corrupt a bill.
    it('refuses a raw amount_paid below zero by the exact constraint name', function (): void {
        $bill = cpBill('1000.00');

        try {
            DB::transaction(fn () => DB::table('bills')->where('id', $bill->getKey())->update([
                'amount_paid' => '-1.0000',
                'amount_due' => '1001.0000',
                'updated_at' => now(),
            ]));
            expect()->fail('a negative amount_paid should have been refused by bills_non_negative_check');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('bills_non_negative_check');
        }

        expect($bill->fresh()->amount_paid)->toBe('0.0000');
    });
});
