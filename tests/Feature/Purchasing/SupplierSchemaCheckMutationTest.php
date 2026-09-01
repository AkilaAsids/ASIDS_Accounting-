<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QA non-vacuity evidence for the savepoint-wrapped CHECK negatives in `SupplierSchemaTest`.
 *
 * The four CHECK-negative tests in `SupplierSchemaTest` wrap their failing insert in `DB::transaction`
 * (a savepoint), so a PG 25P02 does not abort the surrounding RefreshDatabase transaction. This file
 * proves that fix did not turn those assertions vacuous: for each constraint it drops the CHECK and then
 * runs the *identical* savepoint-wrapped bad insert, and shows it now succeeds. If the throw in the real
 * test came from the savepoint machinery rather than the constraint, dropping the constraint would leave
 * the real test still passing — and this mutation would fail. It passing means each real negative fails
 * for the right reason: the CHECK, and only the CHECK, rejects the row.
 *
 * The DROP runs inside the RefreshDatabase transaction and is rolled back with it, so no schema change
 * outlives the test. `asids_app` owns the table it migrated, so it may ALTER it here.
 *
 * This is QA verification of the tests themselves, not a supplier behaviour. It adds no production code.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();
});

/**
 * A raw, well-formed supplier row with the caller's overrides applied. Named distinctly from the schema
 * suite's `supplierRow()` so the two global Pest helpers do not collide.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mutationSupplierRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'code' => 'S-0001',
        'name' => 'Silva Suppliers',
        'status' => 'active',
        'archived_at' => null,
        'is_vat_registered' => false,
        'payment_terms_days' => 30,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

function constraintPresent(string $name): bool
{
    return DB::selectOne(
        'SELECT count(*) AS n FROM pg_constraint WHERE conname = ?',
        [$name],
    )->n > 0;
}

describe('the CHECK negatives are non-vacuous (drop the constraint → the same savepoint insert succeeds)', function (): void {
    it('the status CHECK is the only thing rejecting a status outside the enum', function (): void {
        expect(constraintPresent('suppliers_status_check'))->toBeTrue();

        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_status_check');

        // Identical to SupplierSchemaTest's "refuses a status outside the enum", which asserts this THROWS.
        // With the CHECK gone it must not throw, and the row must land — proving the throw was the CHECK's.
        expect(fn () => DB::transaction(fn () => DB::table('suppliers')->insert(mutationSupplierRow(['code' => 'S-0002', 'status' => 'dormant']))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(1);
    });

    it('the archived CHECK is the only thing rejecting an archived status with no timestamp', function (): void {
        expect(constraintPresent('suppliers_archived_check'))->toBeTrue();

        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_archived_check');

        expect(fn () => DB::transaction(fn () => DB::table('suppliers')->insert(mutationSupplierRow(['code' => 'S-0002', 'status' => 'archived', 'archived_at' => null]))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(1);
    });

    it('the archived CHECK is the only thing rejecting an active status carrying an archive timestamp', function (): void {
        expect(constraintPresent('suppliers_archived_check'))->toBeTrue();

        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_archived_check');

        expect(fn () => DB::transaction(fn () => DB::table('suppliers')->insert(mutationSupplierRow(['code' => 'S-0002', 'status' => 'active', 'archived_at' => now()]))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(1);
    });

    it('the VAT CHECK is the only thing rejecting registration without a number', function (): void {
        expect(constraintPresent('suppliers_vat_registration_check'))->toBeTrue();

        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_vat_registration_check');

        expect(fn () => DB::transaction(fn () => DB::table('suppliers')->insert(mutationSupplierRow(['code' => 'S-0002', 'is_vat_registered' => true, 'vat_registration_number' => null]))))
            ->not->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(1);
    });
});
