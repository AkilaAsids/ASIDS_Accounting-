<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QA-added: the duplicate supplier-invoice-number guard under concurrency — the "unique index as the authority"
 * half of Gate-1 dec. 5 (ADR 0019 §A4, §C6).
 *
 * `BillDraftTest` covers the read-then-write pre-check (`assertNoDuplicateSupplierNumber`): the same number
 * refused for one supplier, allowed across suppliers/companies, freed on delete, re-checked on update.
 * `BillSchemaTest` covers the index rejecting a duplicate at the database. Neither exercises the *service's
 * translation* of that database violation: `replaceLinesGuardingDuplicate()` catches the
 * `UniqueConstraintViolationException` whose message names `bills_company_supplier_invoice_number_unique` and
 * re-throws it as the same `bill-duplicate-supplier-number` refusal the pre-check produces
 * (`BillService::isDuplicateSupplierNumberViolation()`).
 *
 * That branch is the AP double-payment control under a real race — two requests both pass the pre-check because
 * neither can see the other's uncommitted row, and only the index decides the winner. The ADR names this path a
 * mirror-drift risk ("hand-copying invites a renamed problem code"): if the hard-coded constraint name drifted,
 * a genuine concurrent double-record would escape as a raw 500 rather than the friendly refusal, silently
 * defeating the control. This mirrors the two `SupplierTest` "code-uniqueness race" cases for the supplier side.
 *
 * Non-vacuity: the first test anchors the exact exception type and constraint name the translation keys on (a
 * raw duplicate insert throws `UniqueConstraintViolationException` naming that index); the second proves the
 * service turns that same collision into a `BusinessRuleViolation`, never letting the raw exception escape. The
 * contrast is the proof — if the catch or the name-match were wrong, the second test's `catchPlatformException`
 * would not catch the escaping `UniqueConstraintViolationException` and the test would fail.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(
        name: 'Silva Suppliers',
        code: 'SILVA',
    ));

    $this->bills = app(BillService::class);
});

/**
 * One valid expense line for the acme purchases account.
 */
function raceLine(): BillLineData
{
    return new BillLineData(
        description: 'Office supplies',
        quantity: '1',
        unitPrice: '1000.00',
        expenseAccountId: (string) test()->purchases->getKey(),
    );
}

/**
 * A draft created the ordinary way, so a real row holds the number the racer will collide with.
 */
function raceExistingBill(string $number): Bill
{
    return test()->bills->createDraft(test()->company, new BillData(
        supplierId: (string) test()->supplier->getKey(),
        billDate: CarbonImmutable::parse('2026-06-15'),
        supplierInvoiceNumber: $number,
        lines: [raceLine()],
    ));
}

describe('the duplicate supplier-invoice-number guard under concurrency', function (): void {
    it('is enforced by the index the service translation keys on, by that exact name', function (): void {
        raceExistingBill('RACE/1');

        // A raw insert bypassing the service, exactly what a second connection's write becomes once the
        // pre-check has passed on both. Savepoint-wrapped so the PG abort rolls back cleanly.
        try {
            DB::transaction(fn () => DB::table('bills')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenantId,
                'company_id' => $this->company->getKey(),
                'supplier_id' => $this->supplier->getKey(),
                'supplier_invoice_number' => 'RACE/1',
                'number' => null,
                'bill_date' => '2026-06-15',
                'due_date' => '2026-07-15',
                'currency_code' => 'LKR',
                'total' => '0.0000',
                'amount_due' => '0.0000',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            expect()->fail('the duplicate raw insert should have been refused by the unique index');
        } catch (UniqueConstraintViolationException $exception) {
            // The exact type and name `BillService::isDuplicateSupplierNumberViolation()` matches on. If either
            // drifted, the service could not translate the race and this anchors why.
            expect($exception->getMessage())->toContain('bills_company_supplier_invoice_number_unique');
        }
    });

    it('translates the index race into the named refusal, never a raw query exception', function (): void {
        raceExistingBill('RACE/2');

        // The racer, built the way `createDraft()` builds a bill but bypassing `assertNoDuplicateSupplierNumber`
        // — the deterministic stand-in for two requests that both read "free" before either wrote.
        $racer = new Bill;
        $racer->company_id = $this->company->getKey();
        $racer->supplier_id = $this->supplier->getKey();
        $racer->branch_id = null;
        $racer->supplier_invoice_number = 'RACE/2';
        $racer->bill_date = CarbonImmutable::parse('2026-06-15');
        $racer->due_date = CarbonImmutable::parse('2026-07-15');
        $racer->currency_code = $this->company->base_currency_code;
        $racer->notes = null;
        $racer->terms = null;
        $racer->status = BillStatus::Draft;
        $racer->exchange_rate = null;
        $racer->number = null;
        $racer->posted_at = null;
        $racer->posted_by_id = null;
        $racer->journal_entry_id = null;
        $racer->created_by_id = null;

        $replace = new ReflectionMethod(BillService::class, 'replaceLinesGuardingDuplicate');
        $replace->setAccessible(true);

        // Savepoint-wrapped: the header insert inside aborts the transaction, and the wrapper rolls back to the
        // savepoint and re-throws the translated BusinessRuleViolation, which `catchPlatformException` returns.
        // A raw UniqueConstraintViolationException would not be a PlatformException and would fail this test.
        $exception = catchPlatformException(fn () => DB::transaction(fn () => $replace->invoke(
            $this->bills,
            $racer,
            $this->company,
            [raceLine()],
            null,
            $this->supplier,
        )));

        expect($exception)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($exception->problemCode())->toBe('bill-duplicate-supplier-number');
    });
});
