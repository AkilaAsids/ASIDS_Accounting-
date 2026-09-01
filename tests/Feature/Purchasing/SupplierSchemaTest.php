<?php

declare(strict_types=1);

use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the database refuses about a supplier — Stage 1 of Wave 6 (ADR 0018 §B, §F).
 *
 * The payable-side mirror of `tests/Feature/Sales/TaxCodeSchemaTest.php` and the tenant-isolation
 * block of `CustomerTest.php`. Every insert here goes through the query builder, bypassing the model,
 * the DTO and the service that do not exist yet — so a passing test means the constraint is doing the
 * work rather than the application being polite. That matters in a table a bulk import, a data fix and
 * a future service will all write to.
 *
 * Every negative test first inserts a well-formed baseline row (or asserts `hasTable`), so it fails RED
 * because the table is *absent* rather than passing vacuously on the QueryException any insert into a
 * missing table would throw — the RED has to prove the constraint, not the void.
 *
 * RED expectation before Stage 1 lands: there is no `suppliers` table, so `Schema::hasTable` is false,
 * the baseline inserts throw "relation suppliers does not exist", and the raw isolation tests skip on
 * `isEnforced('suppliers')`.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();
});

/**
 * A raw supplier row, with only what the caller wants to vary overridden. Named distinctly from other
 * modules' row helpers because Pest helpers are global.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function supplierRow(array $overrides = []): array
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

function insertSupplier(array $overrides = []): void
{
    DB::table('suppliers')->insert(supplierRow($overrides));
}

describe('the table shape', function (): void {
    it('carries the mirrored customer columns', function (): void {
        expect(Schema::hasTable('suppliers'))->toBeTrue();

        expect(Schema::hasColumns('suppliers', [
            'id', 'tenant_id', 'company_id', 'branch_id',
            'code', 'name', 'legal_name',
            'tax_identification_number', 'vat_registration_number', 'is_vat_registered',
            'email', 'phone', 'website',
            'address_line_1', 'address_line_2', 'city', 'district', 'postal_code', 'country_code',
            'payment_terms_days', 'notes',
            'status', 'archived_at', 'created_by_id',
            'created_at', 'updated_at', 'deleted_at',
        ]))->toBeTrue();
    });

    it('keeps the tax identification number (Gate-1 decision 4, pre-provisioning Wave 8 WHT)', function (): void {
        expect(Schema::hasTable('suppliers'))->toBeTrue()
            ->and(Schema::hasColumn('suppliers', 'tax_identification_number'))->toBeTrue();
    });

    it('drops the two deferred columns', function (): void {
        // The table must exist for this to mean anything — otherwise it passes vacuously on a schema
        // with no suppliers table at all. credit_limit and the AP/receivable account are deferred to
        // Wave 7 (ADR 0018 §B2): neither has a defined payable-side meaning until bills exist.
        expect(Schema::hasTable('suppliers'))->toBeTrue()
            ->and(Schema::hasColumn('suppliers', 'credit_limit'))->toBeFalse()
            ->and(Schema::hasColumn('suppliers', 'receivable_account_id'))->toBeFalse();
    });
});

describe('the indexes', function (): void {
    it('creates the per-company unique code index and the two trigram indexes', function (): void {
        expect(Schema::hasTable('suppliers'))->toBeTrue();

        $names = collect(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['suppliers']))
            ->pluck('indexname')
            ->all();

        expect($names)->toContain('suppliers_company_code_unique')
            ->and($names)->toContain('suppliers_name_trgm')
            ->and($names)->toContain('suppliers_code_trgm')
            // The composite indexes lead with tenant_id per platform convention and match the RLS predicate.
            ->and($names)->toContain('suppliers_tenant_id_company_id_status_index')
            ->and($names)->toContain('suppliers_company_id_branch_id_index');
    });
});

describe('the check constraints', function (): void {
    it('accepts a well-formed supplier row', function (): void {
        // The positive baseline: proves the table and its defaults accept a valid supplier, so the
        // negative tests below are known to be rejecting on the constraint rather than on the void.
        insertSupplier();

        expect(DB::table('suppliers')->count())->toBe(1);
    });

    it('creates the status, archived and VAT checks but not a credit-limit check', function (): void {
        expect(Schema::hasTable('suppliers'))->toBeTrue();

        $checks = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'suppliers'::regclass AND contype = 'c'"
        ))->pluck('conname')->all();

        expect($checks)->toContain('suppliers_status_check')
            ->and($checks)->toContain('suppliers_archived_check')
            ->and($checks)->toContain('suppliers_vat_registration_check')
            // The credit-limit column is gone, so its check must not survive the mirror (ADR 0018 §B3).
            ->and($checks)->not->toContain('suppliers_credit_limit_check');
    });

    it('refuses a status outside the enum', function (): void {
        insertSupplier(['code' => 'S-0001']);

        // Wrapped in a savepoint (DB::transaction) so the CHECK violation rolls back cleanly instead of
        // aborting the whole RefreshDatabase transaction (PG 25P02) — mirrors IssueInvoiceTest.
        expect(fn () => DB::transaction(fn () => insertSupplier(['code' => 'S-0002', 'status' => 'dormant'])))
            ->toThrow(QueryException::class);

        // Proves it was the CHECK that rejected the row, not a missing table or an unrelated failure.
        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(0);
    });

    it('refuses an archived status with no timestamp', function (): void {
        insertSupplier(['code' => 'S-0001']);

        // Phase 2 learned this on fiscal_periods: a mass update moved status and left the timestamp
        // behind. The CHECK ties them together so the two can never disagree.
        expect(fn () => DB::transaction(fn () => insertSupplier(['code' => 'S-0002', 'status' => 'archived', 'archived_at' => null])))
            ->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(0);
    });

    it('refuses an active status that carries an archive timestamp', function (): void {
        insertSupplier(['code' => 'S-0001']);

        expect(fn () => DB::transaction(fn () => insertSupplier(['code' => 'S-0002', 'status' => 'active', 'archived_at' => now()])))
            ->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(0);
    });

    it('refuses VAT registration without a number', function (): void {
        insertSupplier(['code' => 'S-0001']);

        expect(fn () => DB::transaction(fn () => insertSupplier(['code' => 'S-0002', 'is_vat_registered' => true, 'vat_registration_number' => null])))
            ->toThrow(QueryException::class);

        expect(DB::table('suppliers')->where('code', 'S-0002')->count())->toBe(0);
    });
});

describe('the code is unique per company, case-insensitively, on live rows only', function (): void {
    it('refuses a duplicate code differing only in case within one company', function (): void {
        insertSupplier(['code' => 'ACME-1']);

        // "acme-1" and "ACME-1" are one supplier to everyone except a naive unique constraint.
        expect(fn () => insertSupplier(['code' => 'acme-1']))
            ->toThrow(QueryException::class);
    });

    it('lets another company in the same workspace reuse a code', function (): void {
        insertSupplier(['code' => 'SHARED']);

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->acme['owner']);

        // Codes are unique per company, not per workspace: two companies that both buy from the same
        // shop keep separate supplier records (ADR 0018 §B3).
        DB::table('suppliers')->insert(supplierRow(['code' => 'SHARED', 'company_id' => $second->getKey()]));

        expect(DB::table('suppliers')->where('code', 'SHARED')->count())->toBe(2);
    });

    it('frees a code for reuse once the row is soft-deleted', function (): void {
        insertSupplier(['code' => 'REUSE']);
        DB::table('suppliers')->where('code', 'REUSE')->update(['deleted_at' => now()]);

        // The unique index excludes soft-deleted rows, so a code typed by mistake is not burned for ever.
        insertSupplier(['code' => 'REUSE']);

        expect(DB::table('suppliers')->whereNull('deleted_at')->where('code', 'REUSE')->count())->toBe(1);
    });
});

describe('forced row level security', function (): void {
    it('enables and forces row level security with a tenant-isolation policy', function (): void {
        // Asserted at the schema level (role-independent) so it holds even when the suite happens to
        // connect as a bypassing role. FORCE is the line that makes the policy apply to the table's
        // owner too — without it CI once ran RLS tests vacuously (ADR 0018 §B5).
        $relation = DB::selectOne(
            'SELECT relrowsecurity AS enabled, relforcerowsecurity AS forced FROM pg_class WHERE relname = ?',
            ['suppliers'],
        );

        expect($relation)->not->toBeNull()
            ->and((bool) $relation->enabled)->toBeTrue()
            ->and((bool) $relation->forced)->toBeTrue();

        $policies = collect(DB::select('SELECT policyname FROM pg_policies WHERE tablename = ?', ['suppliers']))
            ->pluck('policyname')
            ->all();

        expect($policies)->toContain('suppliers_tenant_isolation');
    });

    it('hides another workspace’s suppliers from raw SQL', function (): void {
        insertSupplier();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Raw SQL, bypassing Eloquent's global scope, so the database policy is the only thing that can
        // hide the row. An Eloquent assertion here would pass with the policies switched off.
        expect(DB::table('suppliers')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('suppliers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a write naming another tenant', function (): void {
        $acmeTenantId = $this->tenantId;
        $acmeCompanyId = $this->company->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('suppliers')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $acmeCompanyId,
            'code' => 'SNEAK',
            'name' => 'Planted',
            'status' => 'active',
            'payment_terms_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('suppliers'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
