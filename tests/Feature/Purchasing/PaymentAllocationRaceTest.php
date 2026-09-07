<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBeRecorded;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * QA-added: the multi-bill allocation guards under concurrency — the "database authority behind the readable
 * refusal" half of the per-bill cap and the full-allocation rule (ADR 0020 §A4, §F1).
 *
 * `RecordPaymentTest` covers the pre-transaction checks (the cap, outstanding, full allocation) on the happy
 * path. This suite exercises what holds when two payments touch the same bills — the property §F1 rests on:
 * the locked re-read is authoritative, and `bills_amount_due_non_negative_check` is the database backstop the
 * app's readable cap check is layered over.
 *
 * True OS-level concurrency is not reproducible in a single-process test, so — exactly as
 * `BillDuplicateNumberRaceTest` uses a deterministic stand-in for two racing requests — these prove the
 * guarantees deterministically:
 *
 *   * the database refuses a raw over-allocation that would drive `amount_due` negative, so even a service bug
 *     that let an over-cap allocation through could not corrupt a payable (the authority under a race);
 *   * a bill settled by a first payment cannot be settled again by a second — the loser is refused readably,
 *     no lost update, no double settlement;
 *   * the payload's bill order does not matter: the service locks in ascending id order regardless of how the
 *     allocations were supplied, which is the deadlock-free property of §F1.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

    $this->payments = app(PaymentService::class);
});

function raceBill(string $unitPrice): Bill
{
    static $counter = 0;
    $counter++;

    return app(BillService::class)->post(
        app(BillService::class)->createDraft(test()->company, new BillData(
            supplierId: (string) test()->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'RACE-'.$counter,
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

/**
 * @param  list<PaymentAllocationData>  $allocations
 * @param  numeric-string  $amount
 */
function racePayment(array $allocations, string $amount): PaymentData
{
    return new PaymentData(
        supplierId: (string) test()->supplier->getKey(),
        bankAccountId: (string) test()->bank->getKey(),
        paymentDate: CarbonImmutable::parse('2026-06-15'),
        amount: $amount,
        allocations: $allocations,
    );
}

describe('the database authority behind the per-bill cap', function (): void {
    it('refuses a raw over-allocation that would drive amount_due negative, by the exact constraint name', function (): void {
        $bill = raceBill('1000.00');

        // A raw UPDATE bypassing the service — what a lost update would produce if the locked re-read failed.
        // Savepoint-wrapped so the PG abort rolls back cleanly.
        try {
            DB::transaction(fn () => DB::table('bills')->where('id', $bill->getKey())->update([
                'amount_paid' => '1300.0000',
                'amount_due' => '-300.0000',
                'status' => 'paid',
                'updated_at' => now(),
            ]));
            expect()->fail('the over-allocation should have been refused by bills_amount_due_non_negative_check');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('bills_amount_due_non_negative_check');
        }

        // The bill is untouched — the abort rolled back.
        expect($bill->fresh()->amount_due)->toBe('1000.0000');
    });
});

describe('two payments contending for the same bill', function (): void {
    it('lets the first settle it and refuses the second readably — no double settlement', function (): void {
        $bill = raceBill('1000.00');

        $this->payments->record($this->company, racePayment([
            new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00'),
        ], '1000.00'), $this->owner);

        // The second payment finds the bill already Paid — refused readably rather than driving it negative or
        // double-recording the disbursement.
        $exception = catchPlatformException(fn () => $this->payments->record($this->company, racePayment([
            new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1.00'),
        ], '1.00'), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-bill-not-outstanding');

        // Exactly one settlement landed.
        expect($bill->fresh()->amount_paid)->toBe('1000.0000')
            ->and(DB::table('payment_allocations')->where('bill_id', $bill->getKey())->count())->toBe(1);
    });

    it('refuses a second payment that would exceed the remaining due after a partial first', function (): void {
        $bill = raceBill('1000.00');

        $this->payments->record($this->company, racePayment([
            new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '600.00'),
        ], '600.00'), $this->owner);

        // 500 remains due only 400 — the cap refuses it, and the DB backstop would too.
        $exception = catchPlatformException(fn () => $this->payments->record($this->company, racePayment([
            new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '500.00'),
        ], '500.00'), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-allocation-exceeds-due');

        expect($bill->fresh()->amount_due)->toBe('400.0000');
    });
});

describe('the ascending-id lock order is insensitive to payload order (§F1)', function (): void {
    it('settles every bill regardless of the order the allocations were supplied in', function (): void {
        $a = raceBill('600.00');
        $b = raceBill('900.00');

        // Supplied b-before-a; the service locks ascending by id regardless — the deadlock-free order.
        $ordered = collect([$a, $b])->sortByDesc(fn (Bill $bill): string => (string) $bill->getKey())->values();

        $payment = $this->payments->record($this->company, racePayment([
            new PaymentAllocationData(billId: (string) $ordered[0]->getKey(), amount: '300.00'),
            new PaymentAllocationData(billId: (string) $ordered[1]->getKey(), amount: '300.00'),
        ], '600.00'), $this->owner);

        expect($payment->allocations)->toHaveCount(2)
            ->and($a->fresh()->amount_due)->toBe('300.0000')
            ->and($b->fresh()->amount_due)->toBe('600.0000');
    });
});
