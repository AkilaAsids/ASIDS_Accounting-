<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Infrastructure\EloquentPayableBalanceProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The payables probe, answering for real — Stage 6 of Wave 7 (ADR 0019 §E).
 *
 * Wave 6 wrote three rules `SupplierService` could not enforce — a supplier the company still owes cannot be
 * archived, one named by any bill cannot be deleted or recoded — and bound `NoPayables`, which truthfully
 * reported that no bill table existed. This wave moves the binding to `EloquentPayableBalanceProbe`. Nothing in
 * `SupplierService` changes; the seam is what does the work, exactly as Sales' `ReceivableBalanceProbe` did.
 *
 * This rewrites the dormant Wave-6 `PayableBalanceProbeTest` to mirror `ReceivableBalanceProbeTest`, including
 * its one binding assertion — the check that would have caught Sales closing a milestone with the seam still
 * unbound — plus the four "rules this activates" cases (the blast radius, Gate-1 dec. 6).
 *
 * Two questions, and the difference between them is the substance. Owing money is about the present: a paid bill
 * is settled, a cancelled one no longer owed, a draft never owed. Being named by a bill is about the record, and
 * every one of those still names the supplier — so the same fixture gives a balance of zero and `hasAnyBill` of
 * true, which is the pair of rules working as written.
 *
 * Cancellation is deferred this wave, so the not-outstanding states (`cancelled`, `paid`, `partially_paid`) are
 * reached with raw SQL — the immutability trigger permits a status change on a posted bill, and the phase CHECK
 * on `amount_paid` is dropped where a payment must be simulated, exactly as the receivable suite does.
 *
 * RED expectation before Stage 6 lands: the provider still binds `NoPayables`, so the binding assertion fails;
 * and before Stage 5, `BillService` does not exist, so the fixtures error.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(
        name: 'Silva Suppliers',
        code: 'SILVA',
    ));

    $this->probe = app(PayableBalanceProbe::class);
});

/**
 * A draft for the acme supplier, built through the service so its figures are real. Fresh number each call.
 */
function payableDraft(string $unitPrice = '1000.00', ?Supplier $supplier = null): Bill
{
    static $counter = 0;
    $counter++;

    $supplier ??= test()->supplier;

    return app(BillService::class)->createDraft(test()->company, new BillData(
        supplierId: (string) $supplier->getKey(),
        billDate: CarbonImmutable::parse('2026-06-15'),
        supplierInvoiceNumber: 'SUP-'.$supplier->getKey().'-'.$counter,
        lines: [new BillLineData(
            description: 'Office supplies',
            quantity: '1',
            unitPrice: $unitPrice,
            expenseAccountId: (string) test()->purchases->getKey(),
        )],
    ));
}

/**
 * A posted bill for the acme supplier.
 */
function payableBill(string $unitPrice = '1000.00', ?Supplier $supplier = null): Bill
{
    return app(BillService::class)->post(payableDraft($unitPrice, $supplier), test()->owner);
}

describe('the binding', function (): void {
    it('is the real implementation, not the stub', function (): void {
        // The one assertion that would have caught the milestone closing with the seam still unbound (ADR §E,
        // mirror `ReceivableBalanceProbeTest`). NoPayables is kept but must no longer be the bound default.
        expect(app(PayableBalanceProbe::class))->toBeInstanceOf(EloquentPayableBalanceProbe::class);
    });
});

describe('what a supplier is owed', function (): void {
    it('is zero when they have never been billed', function (): void {
        expect($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('counts a posted bill in full', function (): void {
        $bill = payableBill('1000.00');

        expect($bill->amount_due)->toBe('1000.0000')
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('1000.0000');
    });

    it('counts what is left on a partially paid bill, not its total', function (): void {
        $bill = payableBill('1000.00');

        // Wave 8 territory reached the only way it can be today: `amount_paid` is pinned at zero by a
        // phase-scoped CHECK, so the constraint comes off to move it. What matters is that the probe reads
        // `amount_due` rather than recomputing `total - amount_paid` itself.
        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase');
        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'partially_paid',
            'amount_paid' => '400.0000',
            'amount_due' => '600.0000',
        ]);

        expect($this->probe->outstandingBalance($this->supplier))->toBe('600.0000');
    });

    it('sums several outstanding bills', function (): void {
        payableBill('1000.00');
        payableBill('250.50');
        payableBill('99.49');

        expect($this->probe->outstandingBalance($this->supplier))->toBe('1349.9900');
    });

    it('ignores a draft, which is not yet owed', function (): void {
        payableDraft('5000.00');

        expect($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('ignores a cancelled bill', function (): void {
        $bill = payableBill('1000.00');

        // Cancellation is deferred, so the state is reached with raw SQL — the trigger permits a status change on
        // a posted bill. `scopeOutstanding` excludes it whatever its amount_due.
        DB::table('bills')->where('id', $bill->getKey())->update(['status' => 'cancelled']);

        expect($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('ignores a paid bill, which is settled', function (): void {
        $bill = payableBill('1000.00');

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase');
        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'paid',
            'amount_paid' => '1000.0000',
            'amount_due' => '0.0000',
        ]);

        expect($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('returns a decimal string at the ledger scale, never a float', function (): void {
        payableBill('1000.00');

        $balance = $this->probe->outstandingBalance($this->supplier);

        // `SupplierService` compares this with `bccomp` at `Money::SCALE`, which needs the scale actually present.
        expect($balance)->toBeString()
            ->and($balance)->toBe('1000.0000')
            ->and(substr($balance, strpos($balance, '.') + 1))->toHaveLength(Money::SCALE);
    });

    it('returns a scaled string rather than an integer when nothing is owed', function (): void {
        expect($this->probe->outstandingBalance($this->supplier))->toBeString()->toBe('0.0000');
    });
});

describe('whether a supplier has ever been billed', function (): void {
    it('is false before any bill exists', function (): void {
        expect($this->probe->hasAnyBill($this->supplier))->toBeFalse();
    });

    it('is true for a draft', function (): void {
        payableDraft();

        // Owes nothing, and is still named by a document. The two answers differ on purpose.
        expect($this->probe->hasAnyBill($this->supplier))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('is true for a posted bill', function (): void {
        payableBill();

        expect($this->probe->hasAnyBill($this->supplier))->toBeTrue();
    });

    it('is true for a paid bill', function (): void {
        $bill = payableBill('1000.00');

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase');
        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'paid', 'amount_paid' => '1000.0000', 'amount_due' => '0.0000',
        ]);

        // Settled and still undeletable: the bill is a statutory record that names them.
        expect($this->probe->hasAnyBill($this->supplier))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });

    it('is true for a cancelled bill', function (): void {
        $bill = payableBill('1000.00');
        DB::table('bills')->where('id', $bill->getKey())->update(['status' => 'cancelled']);

        expect($this->probe->hasAnyBill($this->supplier))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->supplier))->toBe('0.0000');
    });
});

describe('company and tenant isolation', function (): void {
    it('does not count a sibling company’s bill', function (): void {
        // Two companies in one workspace share a `tenant_id`; only the explicit company filter separates them.
        $sibling = app(CompanyService::class)->create(new CreateCompanyData(name: 'Acme Exports', code: 'EXPORTS'), $this->owner);
        app(ChartTemplateService::class)->apply($sibling);
        app(FiscalCalendarService::class)->openYearContaining($sibling, CarbonImmutable::parse('2026-06-15'));

        $siblingExpense = Account::query()->forCompany($sibling->getKey())->where('code', '5100')->firstOrFail();
        $siblingSupplier = app(SupplierService::class)->create($sibling, new SupplierData(name: 'Silva', code: 'SILVA'));

        $draft = app(BillService::class)->createDraft($sibling, new BillData(
            supplierId: (string) $siblingSupplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'SIB-1',
            lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '7500.00', expenseAccountId: (string) $siblingExpense->getKey())],
        ));
        app(BillService::class)->post($draft, $this->owner);

        expect($this->probe->outstandingBalance($this->supplier))->toBe('0.0000')
            ->and($this->probe->hasAnyBill($this->supplier))->toBeFalse()
            ->and($this->probe->outstandingBalance($siblingSupplier))->toBe('7500.0000')
            ->and($this->probe->hasAnyBill($siblingSupplier))->toBeTrue();
    });

    it('cannot see another tenant’s bills', function (): void {
        payableBill('1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(Bill::query()->count())->toBe(0);

        $this->withinTenant($this->acme['tenant']);

        expect($this->probe->outstandingBalance($this->supplier))->toBe('1000.0000');
    });
});

describe('the rules this activates (the blast radius)', function (): void {
    it('refuses to archive a supplier who is still owed', function (): void {
        payableBill('1000.00');

        // Live for the first time. `SupplierService::archive()` has carried this rule since Wave 6 and could
        // never fire it, because the stub reported a balance of zero for everybody (ADR §E).
        $exception = catchPlatformException(fn () => app(SupplierService::class)->archive($this->supplier->fresh()));

        expect($exception->problemCode())->toBe('supplier-has-outstanding-balance');
    });

    it('allows archiving once the balance is cleared', function (): void {
        $bill = payableBill('1000.00');
        DB::table('bills')->where('id', $bill->getKey())->update(['status' => 'cancelled']);

        // The rule is about money owed, not about having been billed — so a no-longer-outstanding bill releases it.
        $archived = app(SupplierService::class)->archive($this->supplier->fresh());

        expect($archived->status->value)->toBe('archived');
    });

    it('refuses to change the code of a billed supplier', function (): void {
        payableBill('1000.00');

        $exception = catchPlatformException(fn () => app(SupplierService::class)->update($this->supplier->fresh(), ['code' => 'SILVA2']));

        expect($exception->problemCode())->toBe('supplier-code-locked');
    });

    it('refuses to delete a billed supplier, even after the balance is cleared', function (): void {
        $bill = payableBill('1000.00');
        DB::table('bills')->where('id', $bill->getKey())->update(['status' => 'cancelled']);

        // `hasAnyBill()` rather than the balance: the document names them whatever became of it.
        $exception = catchPlatformException(fn () => app(SupplierService::class)->delete($this->supplier->fresh()));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('supplier-has-bills');
    });

    it('still lets a never-billed supplier be archived and deleted', function (): void {
        // The other side of the blast radius: the rules bite only where a bill exists. A supplier with none is
        // unaffected by the rebind.
        $untouched = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Fresh', code: 'FRESH'));

        $archived = app(SupplierService::class)->archive($untouched);
        expect($archived->status->value)->toBe('archived');

        app(SupplierService::class)->delete($untouched->fresh());
        expect(Supplier::query()->find($untouched->getKey()))->toBeNull();
    });
});
