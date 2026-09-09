<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Carbon\CarbonImmutable;

/**
 * The payables probe's `amount_due` invariant made load-bearing by real payments — Stage 6 of Wave 8a (ADR
 * 0020 §F2, AC-2.4, Gate-1 Risk 5).
 *
 * `EloquentPayableBalanceProbe::outstandingBalance()` sums `amount_due` over outstanding bills, and its own
 * comment (`EloquentPayableBalanceProbe.php:48-51`) predicted this wave: it reads the stored `amount_due`
 * "because … Wave 8 drops [the phase CHECK], and at that point the stored column is the one the payment
 * allocation maintains." This wave is that point. This suite is verification only — no production code — proving
 * the invariant holds once payments move the column:
 *
 *   * after a partial payment, `outstandingBalance()` == Σ `amount_due` == Σ (`total − amount_paid`);
 *   * a fully-paid bill flips to `Paid`, leaves `scopeOutstanding()`, and drops the supplier balance by exactly
 *     what was allocated — a settled bill contributes nothing (AC-2.4).
 *
 * RED expectation before Stage 4 lands: `PaymentService` does not exist, so the fixtures error.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

    $this->payments = app(PaymentService::class);
    $this->probe = app(PayableBalanceProbe::class);
});

function probeBill(string $unitPrice): Bill
{
    static $counter = 0;
    $counter++;

    return app(BillService::class)->post(
        app(BillService::class)->createDraft(test()->company, new BillData(
            supplierId: (string) test()->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'PROBE-'.$counter,
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: $unitPrice,
                expenseAccountId: (string) test()->purchases->getKey(),
            )],
        )),
        test()->owner,
    );
}

function probePay(Bill $bill, string $amount): void
{
    test()->payments->record(test()->company, new PaymentData(
        supplierId: (string) test()->supplier->getKey(),
        bankAccountId: (string) test()->bank->getKey(),
        paymentDate: CarbonImmutable::parse('2026-06-15'),
        amount: $amount,
        allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: $amount)],
    ), test()->owner);
}

/**
 * Σ (total − amount_paid) over outstanding bills — the arithmetic the stored `amount_due` must agree with.
 *
 * @return numeric-string
 */
function outstandingByArithmetic(): string
{
    $sum = Money::zero('LKR');

    foreach (Bill::query()->forCompany((string) test()->company->getKey())->outstanding()->get() as $bill) {
        $sum = $sum->plus(Money::of($bill->total, 'LKR')->minus(Money::of($bill->amount_paid, 'LKR')));
    }

    return $sum->toDecimalString();
}

describe('the amount_due invariant after payments', function (): void {
    it('reports Σ amount_due == Σ (total − amount_paid) after a partial payment (AC-2.4)', function (): void {
        $bill = probeBill('1000.00');

        probePay($bill, '400.00');

        // The stored column the probe reads, the bill's own arithmetic, and the probe's answer all agree.
        expect($this->probe->outstandingBalance($this->supplier))->toBe('600.0000')
            ->and(outstandingByArithmetic())->toBe('600.0000')
            ->and($bill->fresh()->amount_due)->toBe('600.0000');
    });

    it('drops the balance by exactly what was allocated, across several bills', function (): void {
        $a = probeBill('1000.00');
        $b = probeBill('500.00');

        expect($this->probe->outstandingBalance($this->supplier))->toBe('1500.0000');

        probePay($a, '400.00');

        // Down by exactly the 400 allocated — no more, no less.
        expect($this->probe->outstandingBalance($this->supplier))->toBe('1100.0000')
            ->and(outstandingByArithmetic())->toBe('1100.0000');
    });

    it('drops a fully-paid bill out of the outstanding set entirely (AC-2.4)', function (): void {
        $a = probeBill('1000.00');
        $b = probeBill('500.00');

        probePay($a, '1000.00');

        $freshA = $a->fresh();

        // Paid, and gone from the outstanding set — so it contributes nothing to the balance, which is now
        // just the untouched second bill.
        expect($freshA->status->value)->toBe('paid')
            ->and($freshA->amount_due)->toBe('0.0000')
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('500.0000')
            ->and(outstandingByArithmetic())->toBe('500.0000');
    });

    it('reaches zero when every bill is settled', function (): void {
        $bill = probeBill('1000.00');

        probePay($bill, '600.00');
        probePay($bill, '400.00');

        expect($bill->fresh()->status->value)->toBe('paid')
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('0.0000')
            ->and($this->probe->hasAnyBill($this->supplier))->toBeTrue();
    });
});
