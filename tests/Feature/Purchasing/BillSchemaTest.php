<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the database refuses about a bill — Stage 2 of Wave 7 (ADR 0019 §A).
 *
 * The payable-side mirror of `tests/Feature/Sales/SalesInvoiceSchemaTest.php` and
 * `IssuedInvoiceImmutabilityTest.php`. Every insert here goes through the query builder, bypassing the model,
 * the DTO and the service that do not exist yet — so a passing test means the constraint is doing the work
 * rather than the application being polite. That matters in a table a bulk import, a data fix and a future
 * service will all write to.
 *
 * The constraints proving the *posted boundary* are the point, exactly as they were for issuing on the sales
 * side. Wave 7 cannot cancel anything, so `cancelled` is reserved by the status CHECK and never exercised as a
 * transition — the boundary reserved without a later widening migration (ADR §A4).
 *
 * TWO DEPARTURES FROM THE SALES MIRROR
 * ------------------------------------
 * A bill has a `supplier_invoice_number` (NOT NULL, the supplier's own number) and no free-text `reference`; and
 * it carries the duplicate-guard unique on `(company_id, supplier_id, supplier_invoice_number)` that has no sales
 * analogue — a customer does not assign your invoice a number, a supplier does, and recording the same supplier
 * bill twice is the classic AP double-payment risk (ADR §A2, §A4, Gate-1 dec. 5).
 *
 * Every negative first inserts a well-formed baseline row (or asserts `hasTable`/`hasColumn`), so it fails RED
 * because the table is *absent* rather than passing vacuously on the QueryException any insert into a missing
 * table would throw. The CHECK negatives are wrapped in `DB::transaction()` (a savepoint) so a PG 25P02 rolls
 * back cleanly instead of aborting the surrounding RefreshDatabase transaction — the Wave-6 `SupplierSchemaTest`
 * pattern.
 *
 * RED expectation before Stage 2 lands: there is no `bills` table, so `Schema::hasTable` is false, the baseline
 * inserts throw "relation bills does not exist", and the raw isolation tests skip on `isEnforced('bills')`.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

    // A bill line debits expense, so the account is an expense account — mirror the invoice line's income one.
    $this->expense = Account::query()
        ->forCompany($this->company->getKey())
        ->where('code', '5100')
        ->firstOrFail();
});

/**
 * A raw draft bill row. Named distinctly from other suites' row helpers, since Pest helpers are global.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function billRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'supplier_id' => test()->supplier->getKey(),
        'supplier_invoice_number' => 'SUP-INV-001',
        'number' => null,
        'bill_date' => '2026-06-15',
        'due_date' => '2026-07-15',
        'currency_code' => 'LKR',
        'subtotal' => '1000.0000',
        'discount_total' => '0.0000',
        'tax_total' => '180.0000',
        'total' => '1180.0000',
        'amount_paid' => '0.0000',
        'amount_due' => '1180.0000',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

function insertBill(array $overrides = []): string
{
    $row = billRow($overrides);
    DB::table('bills')->insert($row);

    return (string) $row['id'];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertBillLine(string $billId, array $overrides = []): void
{
    DB::table('bill_lines')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'bill_id' => $billId,
        'line_number' => 1,
        'description' => 'Office supplies',
        'quantity' => '1.0000',
        'unit_price' => '1000.0000',
        'line_subtotal' => '1000.0000',
        'tax_rate' => '0.0000',
        'tax_amount' => '0.0000',
        'line_total' => '1000.0000',
        'expense_account_id' => test()->expense->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/**
 * Forces a draft into a posted state with raw SQL.
 *
 * The trigger's `WHEN (OLD.status <> 'draft')` clause means this very update is not caught — the property
 * `BillService::post()` depends on, and worth exercising here.
 */
function forcePosted(string $billId, string $number = 'BILL-2026-06-0001'): void
{
    DB::table('bills')->where('id', $billId)->update([
        'status' => 'posted',
        'number' => $number,
        'posted_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('the table shape', function (): void {
    it('carries the mirrored invoice columns plus the supplier invoice number', function (): void {
        expect(Schema::hasTable('bills'))->toBeTrue();

        expect(Schema::hasColumns('bills', [
            'id', 'tenant_id', 'company_id', 'branch_id',
            'supplier_id', 'supplier_invoice_number', 'number',
            'bill_date', 'due_date',
            'currency_code', 'exchange_rate',
            'subtotal', 'discount_total', 'tax_total', 'total', 'amount_paid', 'amount_due',
            'status', 'posted_at', 'posted_by_id', 'journal_entry_id',
            'notes', 'terms', 'created_by_id',
            'created_at', 'updated_at',
        ]))->toBeTrue();
    });

    it('has no free-text reference, no soft delete, and no cancellation columns this wave', function (): void {
        // The counterparty's reference *is* `supplier_invoice_number`, so a third free-text identifier would be
        // redundant. No soft delete (a draft is hard-deleted, a posted bill is a statutory record). Cancellation
        // columns arrive with the cancel feature, exactly as sales added them later (ADR §A2).
        expect(Schema::hasTable('bills'))->toBeTrue()
            ->and(Schema::hasColumn('bills', 'reference'))->toBeFalse()
            ->and(Schema::hasColumn('bills', 'deleted_at'))->toBeFalse()
            ->and(Schema::hasColumn('bills', 'cancelled_at'))->toBeFalse()
            ->and(Schema::hasColumn('bills', 'cancellation_reason'))->toBeFalse()
            ->and(Schema::hasColumn('bills', 'cancelled_by_id'))->toBeFalse();
    });

    it('names the line account expense, not revenue', function (): void {
        expect(Schema::hasTable('bill_lines'))->toBeTrue()
            ->and(Schema::hasColumn('bill_lines', 'expense_account_id'))->toBeTrue()
            ->and(Schema::hasColumn('bill_lines', 'revenue_account_id'))->toBeFalse();
    });
});

describe('the indexes', function (): void {
    it('creates the two composite indexes and both unique indexes', function (): void {
        expect(Schema::hasTable('bills'))->toBeTrue();

        $names = collect(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['bills']))
            ->pluck('indexname')
            ->all();

        expect($names)->toContain('bills_tenant_id_company_id_status_index')
            ->and($names)->toContain('bills_company_id_supplier_id_bill_date_index')
            // Partial: every draft has a null number (ADR §A4).
            ->and($names)->toContain('bills_company_number_unique')
            // The duplicate-guard, full (not partial), exact-match (ADR §A4 / Gate-1 dec. 5).
            ->and($names)->toContain('bills_company_supplier_invoice_number_unique');
    });
});

describe('the check constraints exist', function (): void {
    it('declares every bill CHECK the mirror and the departures need', function (): void {
        expect(Schema::hasTable('bills'))->toBeTrue();

        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'bills'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('bills_status_check')
            ->and($checks)->toContain('bills_due_after_bill_check')
            ->and($checks)->toContain('bills_number_matches_status_check')
            ->and($checks)->toContain('bills_posted_at_matches_status_check')
            ->and($checks)->toContain('bills_draft_has_no_entry_check')
            ->and($checks)->toContain('bills_total_check')
            ->and($checks)->toContain('bills_amount_due_check')
            ->and($checks)->toContain('bills_non_negative_check')
            ->and($checks)->toContain('bills_single_currency_until_fx_phase')
            ->and($checks)->toContain('bills_no_payments_until_payments_phase');
    });

    it('declares every bill_lines CHECK', function (): void {
        expect(Schema::hasTable('bill_lines'))->toBeTrue();

        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'bill_lines'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('bill_lines_quantity_check')
            ->and($checks)->toContain('bill_lines_single_discount_check')
            ->and($checks)->toContain('bill_lines_discount_percent_range_check')
            ->and($checks)->toContain('bill_lines_tax_rate_range_check')
            ->and($checks)->toContain('bill_lines_rate_needs_code_check')
            ->and($checks)->toContain('bill_lines_total_check');
    });
});

describe('a draft bill', function (): void {
    it('accepts a well-formed draft', function (): void {
        insertBill();

        expect(DB::table('bills')->count())->toBe(1);
    });

    it('accepts a draft with no number', function (): void {
        // Internal numbering is reserved inside the posting transaction, so an abandoned draft consumes none.
        $id = insertBill();

        expect(DB::table('bills')->where('id', $id)->value('number'))->toBeNull();
    });

    it('refuses a due date before the bill date', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'bill_date' => '2026-07-15', 'due_date' => '2026-06-15',
        ])))->toThrow(QueryException::class);
    });

    it('accepts a due date equal to the bill date', function (): void {
        // Due on receipt is a real term, not a missing value.
        insertBill(['bill_date' => '2026-06-15', 'due_date' => '2026-06-15']);

        expect(DB::table('bills')->count())->toBe(1);
    });

    it('refuses a status outside the enum', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill(['supplier_invoice_number' => 'BAD', 'status' => 'sent'])))
            ->toThrow(QueryException::class);

        expect(DB::table('bills')->where('supplier_invoice_number', 'BAD')->count())->toBe(0);
    });

    it('accepts every status the enum declares', function (string $status): void {
        // All five exist from the start (ADR §A4) so cancellation and payments add behaviour rather than a
        // CHECK-widening migration. The non-draft states need the columns the boundary CHECKs tie to `status`.
        $overrides = ['supplier_invoice_number' => 'SUP-'.$status];

        if ($status !== 'draft') {
            $overrides['status'] = $status;
            $overrides['number'] = 'BILL-2026-06-0001';
            $overrides['posted_at'] = now();
        }

        insertBill($overrides);

        expect(DB::table('bills')->where('status', $status)->count())->toBe(1);
    })->with(['draft', 'posted', 'partially_paid', 'paid', 'cancelled']);
});

describe('the posted boundary', function (): void {
    it('refuses a draft that carries a number', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'number' => 'BILL-2026-06-0001',
        ])))->toThrow(QueryException::class);
    });

    it('refuses a posted bill with no number', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'status' => 'posted', 'number' => null, 'posted_at' => now(),
        ])))->toThrow(QueryException::class);
    });

    it('refuses a draft that carries a posted timestamp', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill(['supplier_invoice_number' => 'BAD', 'posted_at' => now()])))
            ->toThrow(QueryException::class);
    });

    it('refuses a posted bill with no posted timestamp', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'status' => 'posted', 'number' => 'BILL-2026-06-0001', 'posted_at' => null,
        ])))->toThrow(QueryException::class);
    });

    it('refuses a draft already pointing at a ledger entry', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'journal_entry_id' => (string) Str::uuid7(),
        ])))->toThrow(QueryException::class);
    });
});

describe('the supplier invoice number', function (): void {
    it('is required', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill(['supplier_invoice_number' => null])))
            ->toThrow(QueryException::class);
    });

    it('refuses the same number twice for one supplier in one company', function (): void {
        insertBill(['supplier_invoice_number' => 'INV/001']);

        // The AP double-payment guard: the same supplier cannot have one number recorded twice (Gate-1 dec. 5).
        expect(fn () => DB::transaction(fn () => insertBill(['supplier_invoice_number' => 'INV/001'])))
            ->toThrow(QueryException::class);
    });

    it('allows another supplier in the same company to reuse a number', function (): void {
        insertBill(['supplier_invoice_number' => 'INV/001']);

        $other = Supplier::factory()->create(['company_id' => $this->company->getKey()]);

        // Two suppliers may both number a document INV/001 — the guard is per supplier, not company-wide.
        insertBill(['supplier_id' => $other->getKey(), 'supplier_invoice_number' => 'INV/001']);

        expect(DB::table('bills')->where('supplier_invoice_number', 'INV/001')->count())->toBe(2);
    });

    it('allows another company to reuse a supplier number', function (): void {
        insertBill(['supplier_invoice_number' => 'INV/001']);

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->acme['owner']);
        $secondSupplier = Supplier::factory()->create(['company_id' => $second->getKey()]);

        DB::table('bills')->insert(billRow([
            'company_id' => $second->getKey(),
            'supplier_id' => $secondSupplier->getKey(),
            'supplier_invoice_number' => 'INV/001',
        ]));

        expect(DB::table('bills')->where('supplier_invoice_number', 'INV/001')->count())->toBe(2);
    });
});

describe('bill numbers are unique per company once posted', function (): void {
    it('refuses two posted bills sharing a number', function (): void {
        insertBill(['supplier_invoice_number' => 'A', 'status' => 'posted', 'number' => 'BILL-0001', 'posted_at' => now()]);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'B', 'status' => 'posted', 'number' => 'BILL-0001', 'posted_at' => now(),
        ])))->toThrow(QueryException::class);
    });

    it('allows many drafts, which all have a null number', function (): void {
        // The number index is partial for exactly this reason: a plain unique would permit one draft per company.
        insertBill(['supplier_invoice_number' => 'A']);
        insertBill(['supplier_invoice_number' => 'B']);
        insertBill(['supplier_invoice_number' => 'C']);

        expect(DB::table('bills')->whereNull('number')->count())->toBe(3);
    });
});

describe('the money invariants', function (): void {
    it('refuses an amount due that disagrees with the total', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'total' => '1180.0000', 'amount_due' => '1000.0000',
        ])))->toThrow(QueryException::class);
    });

    it('refuses a total that disagrees with subtotal plus tax', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        // A header disagreeing with itself would post an entry that does not balance (ADR §A4, mirror the sales
        // total invariant folded into create).
        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'subtotal' => '1000.0000', 'tax_total' => '180.0000', 'total' => '1200.0000', 'amount_due' => '1200.0000',
        ])))->toThrow(QueryException::class);
    });

    it('refuses a negative total', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'subtotal' => '-1000.0000', 'tax_total' => '0.0000', 'total' => '-1000.0000', 'amount_due' => '-1000.0000',
        ])))->toThrow(QueryException::class);
    });

    it('accepts a zero total', function (): void {
        // A draft under construction, or fully discounted. The positive-total rule belongs to posting.
        insertBill(['subtotal' => '0.0000', 'tax_total' => '0.0000', 'total' => '0.0000', 'amount_due' => '0.0000']);

        expect(DB::table('bills')->count())->toBe(1);
    });

    it('holds payments at zero until the payments phase', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        // Phase-scoped, dropped by Wave 8. The columns ship now so payments add behaviour rather than a migration.
        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_invoice_number' => 'BAD', 'amount_paid' => '100.0000', 'amount_due' => '1080.0000',
        ])))->toThrow(QueryException::class);
    });

    it('holds the exchange rate at null until the FX phase', function (): void {
        insertBill(['supplier_invoice_number' => 'BASE']);

        expect(fn () => DB::transaction(fn () => insertBill(['supplier_invoice_number' => 'BAD', 'exchange_rate' => '1.0000000000'])))
            ->toThrow(QueryException::class);
    });
});

describe('bill lines', function (): void {
    it('accepts a well-formed line', function (): void {
        insertBillLine(insertBill());

        expect(DB::table('bill_lines')->count())->toBe(1);
    });

    it('refuses a zero quantity', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['quantity' => '0.0000'])))
            ->toThrow(QueryException::class);
    });

    it('accepts a negative quantity', function (): void {
        // How a line-level correction is expressed on an otherwise positive bill.
        insertBillLine(insertBill(), [
            'quantity' => '-1.0000',
            'line_subtotal' => '-1000.0000',
            'line_total' => '-1000.0000',
        ]);

        expect(DB::table('bill_lines')->count())->toBe(1);
    });

    it('refuses both discount forms at once', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['discount_percent' => '10.0000', 'discount_amount' => '50.0000'])))
            ->toThrow(QueryException::class);
    });

    it('accepts either discount form alone', function (): void {
        $bill = insertBill();
        insertBillLine($bill, ['discount_percent' => '10.0000']);
        insertBillLine($bill, ['line_number' => 2, 'discount_amount' => '50.0000']);

        expect(DB::table('bill_lines')->count())->toBe(2);
    });

    it('refuses a discount percentage above one hundred', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['discount_percent' => '100.0001'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a tax rate above one hundred', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['tax_rate' => '1800.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a tax rate with no tax code to attribute it to', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['tax_code_id' => null, 'tax_rate' => '18.0000'])))
            ->toThrow(QueryException::class);
    });

    it('refuses a line total that disagrees with its parts', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, [
            'line_subtotal' => '1000.0000',
            'tax_amount' => '180.0000',
            'line_total' => '1000.0000',
        ])))->toThrow(QueryException::class);
    });

    it('refuses two lines at the same position', function (): void {
        $bill = insertBill();
        insertBillLine($bill, ['line_number' => 1]);

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['line_number' => 1])))
            ->toThrow(QueryException::class);
    });

    it('allows the same position on different bills', function (): void {
        insertBillLine(insertBill(['supplier_invoice_number' => 'A']), ['line_number' => 1]);
        insertBillLine(insertBill(['supplier_invoice_number' => 'B']), ['line_number' => 1]);

        expect(DB::table('bill_lines')->count())->toBe(2);
    });

    it('requires an expense account', function (): void {
        $bill = insertBill();

        expect(fn () => DB::transaction(fn () => insertBillLine($bill, ['expense_account_id' => null])))
            ->toThrow(QueryException::class);
    });

    it('dies with its bill', function (): void {
        $bill = insertBill();
        insertBillLine($bill);

        DB::table('bills')->where('id', $bill)->delete();

        // Cascade, unlike every other foreign key in the module: a line has no meaning apart from its bill.
        expect(DB::table('bill_lines')->count())->toBe(0);
    });
});

describe('referential integrity', function (): void {
    it('refuses a bill for a supplier that does not exist', function (): void {
        // Baseline first, so this fails RED on the absent table rather than passing vacuously on the missing
        // relation — the FK, not the void, must be what rejects the bad row.
        insertBill(['supplier_invoice_number' => 'REF-BASE']);

        expect(fn () => DB::transaction(fn () => insertBill([
            'supplier_id' => (string) Str::uuid7(), 'supplier_invoice_number' => 'REF-BAD',
        ])))->toThrow(QueryException::class);

        expect(DB::table('bills')->where('supplier_invoice_number', 'REF-BAD')->count())->toBe(0);
    });

    it('refuses to delete a supplier that has a bill', function (): void {
        insertBill();

        // Restrict, not cascade. A bill names its supplier and that name has to stay resolvable — the guarantee
        // behind `SupplierService::delete()` refusing a billed supplier, not a duplicate of it (ADR §A2).
        expect(fn () => DB::table('suppliers')->where('id', $this->supplier->getKey())->delete())
            ->toThrow(QueryException::class);
    });
});

describe('a posted bill is frozen', function (): void {
    beforeEach(function (): void {
        $this->bill = insertBill();
        insertBillLine($this->bill);
        forcePosted($this->bill);
    });

    it('refuses every accounting-bearing change', function (string $column, mixed $value): void {
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill)->update([$column => $value])))
            ->toThrow(QueryException::class);
    })->with([
        ['supplier_invoice_number', 'SUP-CHANGED'],
        ['bill_date', '2026-07-01'],
        ['due_date', '2026-08-01'],
        ['subtotal', '9999.0000'],
        ['tax_total', '1.0000'],
        ['discount_total', '5.0000'],
        ['number', 'BILL-2026-06-9999'],
        ['currency_code', 'USD'],
        ['notes', 'Changed after posting'],
        ['terms', 'Changed after posting'],
    ]);

    it('refuses deletion outright', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill)->delete()))
            ->toThrow(QueryException::class);
    });

    it('refuses a return to draft', function (): void {
        // A number has been consumed and a ledger entry exists. Un-posting would strand both (ADR §A5).
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill)->update(['status' => 'draft'])))
            ->toThrow(QueryException::class);
    });

    it('refuses any change to its lines', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bill_lines')->where('bill_id', $this->bill)
            ->update(['quantity' => '99.0000'])))->toThrow(QueryException::class);
    });

    it('refuses deleting a line', function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('bill_lines')->where('bill_id', $this->bill)->delete()))
            ->toThrow(QueryException::class);
    });

    it('refuses adding a line', function (): void {
        // The gap a delete-only guard would leave: appending to a posted document changes what it says.
        expect(fn () => DB::transaction(fn () => DB::table('bill_lines')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->company->getKey(),
            'bill_id' => $this->bill,
            'line_number' => 99,
            'description' => 'Smuggled in after posting',
            'quantity' => '1.0000',
            'unit_price' => '500.0000',
            'line_subtotal' => '500.0000',
            'tax_rate' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '500.0000',
            'expense_account_id' => $this->expense->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });

    it('permits the payment statuses Wave 8 will use', function (string $status): void {
        DB::table('bills')->where('id', $this->bill)->update(['status' => $status, 'updated_at' => now()]);

        expect(DB::table('bills')->where('id', $this->bill)->value('status'))->toBe($status);
    })->with(['partially_paid', 'paid']);

    it('still holds payment figures at zero until the payments phase', function (): void {
        // The trigger permits these columns so Wave 8 adds behaviour rather than loosening a trigger; the
        // phase-scoped CHECK is what stops them moving meanwhile. This proves the trigger has not superseded it.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->where('id', $this->bill)
            ->update(['amount_paid' => '100.0000', 'amount_due' => '1080.0000'])))
            ->toThrow(QueryException::class);
    });
});

describe('forced row level security', function (): void {
    it('enables and forces row level security with a tenant-isolation policy on both tables', function (): void {
        // Asserted at the schema level (role-independent) so it holds even when the suite connects as a bypassing
        // role. FORCE is the line that makes the policy apply to the table's owner too (ADR §A6).
        foreach (['bills', 'bill_lines'] as $table) {
            $relation = DB::selectOne(
                'SELECT relrowsecurity AS enabled, relforcerowsecurity AS forced FROM pg_class WHERE relname = ?',
                [$table],
            );

            expect($relation)->not->toBeNull()
                ->and((bool) $relation->enabled)->toBeTrue()
                ->and((bool) $relation->forced)->toBeTrue();

            $policies = collect(DB::select('SELECT policyname FROM pg_policies WHERE tablename = ?', [$table]))
                ->pluck('policyname')
                ->all();

            expect($policies)->toContain($table.'_tenant_isolation');
        }
    });

    it('hides another workspace’s bills from raw SQL', function (): void {
        insertBill();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('bills')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('bills'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('hides another workspace’s bill lines from raw SQL', function (): void {
        insertBillLine(insertBill());

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Lines carry their own policy: row level security is not transitive (ADR §A6).
        expect(DB::table('bill_lines')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('bill_lines'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a write naming another tenant', function (): void {
        $acmeTenantId = $this->tenantId;
        $acmeCompanyId = $this->company->getKey();
        $acmeSupplierId = $this->supplier->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('bills')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $acmeCompanyId,
            'supplier_id' => $acmeSupplierId,
            'supplier_invoice_number' => 'SNEAK',
            'bill_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency_code' => 'LKR',
            'total' => '0.0000',
            'amount_due' => '0.0000',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('bills'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
