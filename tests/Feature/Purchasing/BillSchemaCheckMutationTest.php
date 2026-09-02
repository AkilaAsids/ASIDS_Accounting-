<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QA non-vacuity evidence for the savepoint-wrapped CHECK negatives in `BillSchemaTest`.
 *
 * The CHECK-negative tests in `BillSchemaTest` wrap their failing insert in `DB::transaction` (a savepoint), so
 * a PG 25P02 does not abort the surrounding RefreshDatabase transaction. This file proves that fix did not turn
 * those assertions vacuous: for each constraint it drops the CHECK and then runs a savepoint-wrapped bad insert
 * crafted to violate *only that one constraint*, and shows it now succeeds. If the throw in the real test came
 * from the savepoint machinery or a missing table rather than the constraint, dropping the constraint would
 * leave the insert still failing — and this mutation would fail. It passing means each real negative fails for
 * the right reason: the CHECK, and only the CHECK, rejects the row.
 *
 * The bill status-tied CHECKs interlock (a `posted` status needs a number and a timestamp), so each bad row
 * below sets the *other* status-tied fields to legal values, leaving the named constraint as the sole
 * violation. That isolation is what makes the mutation meaningful.
 *
 * The DROP runs inside the RefreshDatabase transaction and is rolled back with it, so no schema change outlives
 * the test. `asids_app` owns the table it migrated, so it may ALTER it here. This is QA verification of the
 * tests themselves — it adds no production code (mirror commit 80cc5a9, `SupplierSchemaCheckMutationTest`).
 *
 * RED expectation before Stage 2 lands: no `bills`/`bill_lines` tables, so the DROP statements throw
 * "relation does not exist" and every test errors.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);

    $this->supplier = Supplier::factory()->create(['company_id' => $this->company->getKey()]);
    $this->expense = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
});

/**
 * A raw, well-formed draft bill row with the caller's overrides. Named distinctly from the schema suite's
 * `billRow()` so the two global Pest helpers do not collide.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mutationBillRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'supplier_id' => test()->supplier->getKey(),
        'supplier_invoice_number' => 'MUT-'.Str::random(8),
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

/**
 * A valid draft bill, committed within the RefreshDatabase transaction, to hang line-constraint tests off.
 */
function mutationBill(): string
{
    $row = mutationBillRow();
    DB::table('bills')->insert($row);

    return (string) $row['id'];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mutationBillLineRow(string $billId, array $overrides = []): array
{
    return [
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
    ];
}

function billConstraintPresent(string $name): bool
{
    return DB::selectOne(
        'SELECT count(*) AS n FROM pg_constraint WHERE conname = ?',
        [$name],
    )->n > 0;
}

describe('the bill CHECK negatives are non-vacuous (drop the constraint → the same savepoint insert succeeds)', function (): void {
    it('the status CHECK is the only thing rejecting a status outside the enum', function (): void {
        expect(billConstraintPresent('bills_status_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_status_check');

        // Number and posted_at set to legal `non-draft` values so the status-tied CHECKs are satisfied and only
        // the enum CHECK could object. With it gone the row must land.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow([
            'status' => 'dormant', 'number' => 'BILL-DORMANT', 'posted_at' => now(),
        ]))))->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('status', 'dormant')->count())->toBe(1);
    });

    it('the total CHECK is the only thing rejecting a header that disagrees with itself', function (): void {
        expect(billConstraintPresent('bills_total_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_total_check');

        // total (1200) != subtotal (1000) + tax (180); amount_due kept at total - amount_paid so only the total
        // invariant is broken.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow([
            'subtotal' => '1000.0000', 'tax_total' => '180.0000', 'total' => '1200.0000', 'amount_due' => '1200.0000',
        ]))))->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('total', '1200.0000')->count())->toBe(1);
    });

    it('the amount-due CHECK is the only thing rejecting a due figure that disagrees with the total', function (): void {
        expect(billConstraintPresent('bills_amount_due_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_amount_due_check');

        // amount_due (1000) != total (1180) - amount_paid (0); total still = subtotal + tax.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow(['amount_due' => '1000.0000']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('amount_due', '1000.0000')->count())->toBe(1);
    });

    it('the due-after-bill CHECK is the only thing rejecting a due date before the bill date', function (): void {
        expect(billConstraintPresent('bills_due_after_bill_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_due_after_bill_check');

        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow([
            'bill_date' => '2026-07-15', 'due_date' => '2026-06-15',
        ]))))->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('due_date', '2026-06-15')->count())->toBe(1);
    });

    it('the number-matches-status CHECK is the only thing rejecting a draft that carries a number', function (): void {
        expect(billConstraintPresent('bills_number_matches_status_check'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_number_matches_status_check');

        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow(['number' => 'BILL-DRAFT']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('number', 'BILL-DRAFT')->count())->toBe(1);
    });

    it('the payments-phase CHECK is the only thing rejecting a non-zero amount paid', function (): void {
        expect(billConstraintPresent('bills_no_payments_until_payments_phase'))->toBeTrue();

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase');

        // amount_due kept at total - amount_paid so the amount-due invariant holds and only the phase CHECK objects.
        expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(mutationBillRow([
            'amount_paid' => '100.0000', 'amount_due' => '1080.0000',
        ]))))->not->toThrow(QueryException::class);

        expect(DB::table('bills')->where('amount_paid', '100.0000')->count())->toBe(1);
    });
});

describe('the bill_lines CHECK negatives are non-vacuous', function (): void {
    it('the quantity CHECK is the only thing rejecting a zero quantity', function (): void {
        $bill = mutationBill();

        expect(billConstraintPresent('bill_lines_quantity_check'))->toBeTrue();

        DB::statement('ALTER TABLE bill_lines DROP CONSTRAINT bill_lines_quantity_check');

        expect(fn () => DB::transaction(fn () => DB::table('bill_lines')->insert(mutationBillLineRow($bill, ['quantity' => '0.0000']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('bill_lines')->where('quantity', '0.0000')->count())->toBe(1);
    });

    it('the line-total CHECK is the only thing rejecting a total that disagrees with its parts', function (): void {
        $bill = mutationBill();

        expect(billConstraintPresent('bill_lines_total_check'))->toBeTrue();

        DB::statement('ALTER TABLE bill_lines DROP CONSTRAINT bill_lines_total_check');

        // line_total (999) != line_subtotal (1000) + tax_amount (0); quantity non-zero, no tax code needed.
        expect(fn () => DB::transaction(fn () => DB::table('bill_lines')->insert(mutationBillLineRow($bill, [
            'line_subtotal' => '1000.0000', 'tax_amount' => '0.0000', 'line_total' => '999.0000',
        ]))))->not->toThrow(QueryException::class);

        expect(DB::table('bill_lines')->where('line_total', '999.0000')->count())->toBe(1);
    });
});
