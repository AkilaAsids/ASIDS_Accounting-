<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `WHT Payable` as a system account, and the backfill that CREATES it for companies built before it existed —
 * Stage 2 of Wave 8b (ADR 0021 §A5, §A6).
 *
 * Unlike Trade Payables/Receivables — which the backfill *stamps* onto an existing `2110`/`1130` every prior
 * template already created — `2180 WHT Payable` exists in NO prior template, so the backfill must **create**
 * the account in each existing company. The RLS scaffold (bypass + `assertBypassEffective` +
 * `assertNothingLeftBehind`) is reproduced verbatim from the stamp precedents; only the operation differs.
 *
 * RED expectation before Stage 2 lands: `Account::WHT_PAYABLE` does not exist, the template has no `2180`, and
 * the backfill migration file is absent — so every test errors on the missing constant, the missing account,
 * or the missing `require`.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->template = app(ChartTemplateService::class);

    $this->template->apply($this->company);
});

function whtPayableBackfill(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'src/Core/Accounting/Database/Migrations/2026_09_09_000001_provision_wht_payable_account.php'
    );

    return $migration;
}

/**
 * Simulate a company provisioned before Wave 8b by removing its WHT Payable account entirely (a postings-free
 * account can be hard-deleted). Every company these tests build comes from the current template, which now
 * creates `2180` keyed — so removing it is the only way to reach the pre-8b state the backfill exists for.
 */
function makeLegacyNoWht(string $companyId): void
{
    DB::table('accounts')->where('company_id', $companyId)->where('system_key', 'wht_payable')->delete();
}

/**
 * @return list<Account>
 */
function whtSystemAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::WHT_PAYABLE)
        ->orderBy('code')
        ->get()
        ->all();
}

describe('the constant and the version', function (): void {
    it('exposes the WHT payable system key', function (): void {
        expect(Account::WHT_PAYABLE)->toBe('wht_payable');
    });

    it('bumps the chart template version so existing companies can be found', function (): void {
        // ADR 0021 §A5.4: 2026.09-lk-sme-3 → 2026.09-lk-sme-5 (the account set changed).
        expect(ChartTemplate::VERSION)->toBe('2026.09-lk-sme-5');
    });
});

describe('a newly provisioned company', function (): void {
    it('receives exactly one WHT payable system account, keyed', function (): void {
        $accounts = whtSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $wht = $accounts[0];

        expect($wht->system_key)->toBe('wht_payable')
            ->and($wht->is_system)->toBeTrue()
            ->and($wht->is_postable)->toBeTrue()
            ->and($wht->is_active)->toBeTrue()
            ->and($wht->type)->toBe(AccountType::Liability)
            ->and($wht->normal_balance->value)->toBe('credit')
            ->and($wht->code)->toBe('2180')
            ->and($wht->name)->toBe('WHT Payable')
            ->and($wht->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('receives one even when it declined the starter chart', function (): void {
        // 2180 is in requiredSystemAccounts(), so a company with no chart is still WHT-payable-capable.
        $bare = $this->createWorkspace('bare')['company'];

        $this->template->ensureSystemAccounts($bare);

        $accounts = whtSystemAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Liability)
            ->and($accounts[0]->code)->toBe('2180');
    });

    it('is given no second one by repeated provisioning', function (): void {
        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(whtSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });
});

describe('the backfill (create, not stamp)', function (): void {
    it('creates a keyed WHT payable for a company that has none, under 2100', function (): void {
        makeLegacyNoWht($this->company->getKey());
        expect(whtSystemAccounts($this->company->getKey()))->toHaveCount(0);

        $parent = Account::query()->forCompany($this->company->getKey())->where('code', '2100')->firstOrFail();

        whtPayableBackfill()->up();

        $accounts = whtSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->code)->toBe('2180')
            ->and($accounts[0]->name)->toBe('WHT Payable')
            ->and($accounts[0]->type)->toBe(AccountType::Liability)
            ->and($accounts[0]->normal_balance->value)->toBe('credit')
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and((string) $accounts[0]->parent_id)->toBe((string) $parent->getKey())
            ->and($accounts[0]->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('is idempotent — a second run creates nothing', function (): void {
        makeLegacyNoWht($this->company->getKey());

        $migration = whtPayableBackfill();
        $migration->up();
        $countAfterFirst = Account::query()->forCompany($this->company->getKey())->count();

        $migration->up();
        whtPayableBackfill()->up();

        expect(Account::query()->forCompany($this->company->getKey())->count())->toBe($countAfterFirst)
            ->and(whtSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('leaves a company that already holds the key untouched (a fresh -4 company)', function (): void {
        // The company still has its template-created 2180; the backfill must skip it.
        $before = Account::query()->forCompany($this->company->getKey())->where('system_key', 'wht_payable')->value('id');

        whtPayableBackfill()->up();

        expect(Account::query()->forCompany($this->company->getKey())->where('system_key', 'wht_payable')->value('id'))
            ->toBe($before)
            ->and(whtSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('takes the next free code when 2180 is already used by a hand-numbered account', function (): void {
        makeLegacyNoWht($this->company->getKey());

        // A company that numbered its own account 2180 for something else. The system account still has to
        // exist; its code is not what resolves it, so it takes 2180-1.
        $parent = Account::query()->forCompany($this->company->getKey())->where('code', '2100')->firstOrFail();
        DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'parent_id' => $parent->getKey(),
            'code' => '2180',
            'name' => 'Some Other Liability',
            'type' => 'liability',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'is_system' => false,
            'system_key' => null,
            'is_active' => true,
            'archived_at' => null,
            'template_version' => null,
            'sort_order' => 2180,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        whtPayableBackfill()->up();

        $accounts = whtSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->code)->toBe('2180-1')
            ->and($accounts[0]->system_key)->toBe('wht_payable');
    });

    it('attaches to NULL parent when 2100 is not resolvable', function (): void {
        makeLegacyNoWht($this->company->getKey());

        // A renumbered company with no 2100 heading. The system account still has to exist — flat, valid.
        DB::table('accounts')->where('company_id', $this->company->getKey())->where('code', '2100')
            ->update(['code' => '2000-CL']);

        whtPayableBackfill()->up();

        $accounts = whtSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->parent_id)->toBeNull();
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        makeLegacyNoWht($other['company']->getKey());

        $this->withinTenant($this->acme['tenant']);
        makeLegacyNoWht($this->company->getKey());

        whtPayableBackfill()->up();

        // A migration has no tenant, so without the bypass this would create nothing on a FORCED table and
        // report success. Both tenants provisioned is the proof it did not.
        $keyed = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('company_id', [$this->company->getKey(), $other['company']->getKey()])
            ->where('system_key', 'wht_payable')
            ->where('is_system', true)
            ->pluck('company_id')
            ->sort()
            ->values()
            ->all());

        expect($keyed)->toBe(collect([$this->company->getKey(), $other['company']->getKey()])->sort()->values()->all());
    });

    it('down() deletes only a postings-free account it created', function (): void {
        makeLegacyNoWht($this->company->getKey());

        $migration = whtPayableBackfill();
        $migration->up();

        expect(whtSystemAccounts($this->company->getKey()))->toHaveCount(1);

        $migration->down();

        expect(whtSystemAccounts($this->company->getKey()))->toHaveCount(0);
    });

    it('assertBypassEffective trips when called outside RowLevelSecurity::bypass()', function (): void {
        // The recurring zero-rows trap: without the bypass, a NOBYPASSRLS role sees no rows on a FORCED table
        // and the migration would silently create nothing. The guard must refuse rather than report success.
        $migration = whtPayableBackfill();
        $method = new ReflectionMethod($migration, 'assertBypassEffective');
        $method->setAccessible(true);

        expect(fn () => $method->invoke($migration))->toThrow(RuntimeException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('accounts'),
        'Row level security is not in force for the connecting role (asids_app). The guard cannot trip when the '
        .'role bypasses RLS unconditionally.',
    );

    it('assertNothingLeftBehind trips when a company is left without the account (a partial run)', function (): void {
        // Simulate a partial run: acme has no WHT payable and the backfill did not create it. The guard must
        // refuse to finish rather than leave a company whose first withholding payment fails months later.
        makeLegacyNoWht($this->company->getKey());

        $migration = whtPayableBackfill();
        $method = new ReflectionMethod($migration, 'assertNothingLeftBehind');
        $method->setAccessible(true);

        // Under bypass so the count sees every company; acme now lacks the account → throws.
        expect(fn () => RowLevelSecurity::bypass(fn () => $method->invoke($migration)))
            ->toThrow(RuntimeException::class);
    });
});
