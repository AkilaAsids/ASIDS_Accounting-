<?php

declare(strict_types=1);

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Scopes\TenantScope;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PostgreSQL row level security — the backstop.
 *
 * TenantIsolationTest covers the Eloquent global scope, which handles the ordinary path. These
 * tests cover what the scope cannot reach: raw SQL, `withoutGlobalScopes()`, and anything that
 * touches the connection directly. If the scope is the seatbelt, this is the airbag, and an
 * airbag nobody has ever tested is a decoration.
 *
 * WHY THESE TESTS CAN SILENTLY PASS WITHOUT TESTING ANYTHING
 * ---------------------------------------------------------
 * Two conditions must hold or the policies do not apply at all:
 *
 *   1. `TENANCY_ENFORCE_RLS=true`, so the tenant is published to the session.
 *   2. The connecting role is either not the table owner, or the tables are FORCED.
 *
 * `phpunit.xml` sets enforcement on, matching the test database, which has the RLS migration
 * applied. The two must agree: with policies present but enforcement off, nothing publishes
 * `asids.tenant_id`, every policy evaluates against NULL and the entire suite reads empty result
 * sets with no error anywhere. Fixtures spanning workspaces are built under
 * `RowLevelSecurity::bypass()`, which is what lets enforcement stay on for every test.
 *
 * This file still asserts the conditions itself and **skips loudly** rather than passing when
 * they are not met. A green tick from a test that could not possibly fail is worse than a red one.
 */
beforeEach(function (): void {
    config(['asids.tenancy.enforce_rls' => true]);

    if (! RowLevelSecurity::isEnforced('companies')) {
        $this->markTestSkipped(
            'Row level security is not in force for the connecting role. Connect as a '
            .'NOBYPASSRLS role (asids_app) or ensure the tables are FORCED. These tests would '
            .'otherwise pass without exercising anything.'
        );
    }

    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');
});

it('confirms the policies are actually in force before asserting anything else', function (): void {
    /** @var object{relrowsecurity: bool, relforcerowsecurity: bool} $row */
    $row = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
        ['companies'],
    );

    expect((bool) $row->relrowsecurity)->toBeTrue();

    // FORCE is what makes the policy apply to the table owner too. Without it, a developer
    // connecting as the schema owner runs with protection off and every test here is vacuous.
    expect((bool) $row->relforcerowsecurity)->toBeTrue();
});

it('hides another workspace’s rows from raw SQL', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        // No Eloquent, no scope, no model. This is the query a report or a data export writes,
        // and the one the global scope has no way to constrain.
        $rows = DB::select('SELECT id, tenant_id FROM companies');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->tenant_id)->toBe($this->acme['tenant']->getKey());
    });
});

it('hides another workspace’s rows even when the Eloquent scope is removed', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        // The realistic failure: `withoutGlobalScopes()` left in after debugging. In
        // TenantIsolationTest this read succeeds because enforcement is off; here it must not.
        $companies = Company::query()->withoutGlobalScope(TenantScope::IDENTIFIER)->get();

        expect($companies)->toHaveCount(1);
    });
});

it('returns nothing at all when no workspace is published to the session', function (): void {
    $this->endTenancy();

    // `current_setting` returns '' after a reset, which the policy's NULLIF turns into NULL, so
    // the comparison is NULL and nothing matches. Fail-closed at the database as well as in PHP.
    expect(DB::select('SELECT id FROM companies'))->toBeEmpty();
});

it('refuses a raw insert for another workspace', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        // `companies` uses the strict policy — its WITH CHECK permits only the active tenant, so
        // the database refuses this even though no application code is involved.
        expect(fn () => DB::table('companies')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->globex['tenant']->getKey(),
            'name' => 'Smuggled Ltd',
            'code' => 'SMUG',
            'slug' => 'smuggled',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

it('refuses a raw update targeting another workspace', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        $affected = DB::table('companies')
            ->where('tenant_id', $this->globex['tenant']->getKey())
            ->update(['name' => 'Renamed across tenants']);

        // Zero rows rather than an error: the policy makes the target invisible, so the UPDATE
        // matches nothing. Silent, but correct — and the row is provably untouched below.
        expect($affected)->toBe(0);

        $untouched = RowLevelSecurity::bypass(
            fn () => DB::table('companies')
                ->where('tenant_id', $this->globex['tenant']->getKey())
                ->value('name'),
        );

        expect($untouched)->not->toBe('Renamed across tenants');
    });
});

it('refuses a raw delete targeting another workspace', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        DB::table('companies')->where('tenant_id', $this->globex['tenant']->getKey())->delete();

        $stillThere = RowLevelSecurity::bypass(
            fn () => DB::table('companies')
                ->where('tenant_id', $this->globex['tenant']->getKey())
                ->exists(),
        );

        expect($stillThere)->toBeTrue();
    });
});

it('permits platform-owned rows to remain visible inside a workspace', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        // `settings` uses the nullable-tenant policy: system-scope defaults carry a NULL tenant
        // and are the outermost fallback of the resolver, so hiding them would break resolution
        // rather than protect anything.
        RowLevelSecurity::bypass(fn () => DB::table('settings')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => null,
            'scope' => 'system',
            'scope_id' => null,
            'key' => 'localisation.date_format',
            'type' => 'string',
            'value' => json_encode('Y-m-d'),
            'is_encrypted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $visible = DB::table('settings')->whereNull('tenant_id')->count();

        expect($visible)->toBe(1);
    });
});

it('publishes the tenant to the session variable the policies read', function (): void {
    app(TenantContext::class)->runFor($this->acme['tenant'], function (): void {
        $published = DB::scalar("SELECT current_setting('asids.tenant_id', true)");

        expect($published)->toBe($this->acme['tenant']->getKey());
    });
});

it('clears the session variable when tenancy ends', function (): void {
    $this->withinTenant($this->acme['tenant']);
    $this->endTenancy();

    $published = DB::scalar("SELECT current_setting('asids.tenant_id', true)");

    // Empty, not the previous value. On a pooled PHP-FPM connection a stale value would give the
    // next request the previous request's workspace.
    expect($published)->toBe('');
});

it('reports enforcement accurately, so the deployment check is trustworthy', function (): void {
    // `asids:security-check` gates releases on this. If the probe itself is wrong, the gate is
    // decorative — so it is asserted rather than assumed.
    expect(RowLevelSecurity::isEnforced('companies'))->toBeTrue()
        ->and(RowLevelSecurity::isEnforced('a_table_that_does_not_exist'))->toBeFalse();
});
