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
 * `Foreign Exchange Gain/(Loss)` as a system account, and the backfill that CREATES it for companies built
 * before it existed — Stage 3 of Wave 9a (ADR 0022 §A5, §A6). The mirror of `WhtPayableSystemAccountTest`
 * with `fx_gain_loss`/`4910`/Income/`4000` in place of `wht_payable`/`2180`/Liability/`2100`.
 *
 * `4910` exists in no prior template, so the backfill CREATES it (not stamp). RLS scaffold reproduced
 * verbatim; idempotent by key-absence; hand-numbered collision → `4910-1`; parent `4000` or NULL.
 *
 * RED expectation before Stage 3 lands: `Account::FX_GAIN_LOSS` does not exist, the template has no `4910`,
 * and the backfill migration file is absent.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->template = app(ChartTemplateService::class);

    $this->template->apply($this->company);
});

function fxBackfill(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'src/Core/Accounting/Database/Migrations/2026_09_10_000001_provision_fx_gain_loss_account.php'
    );

    return $migration;
}

function makeLegacyNoFx(string $companyId): void
{
    DB::table('accounts')->where('company_id', $companyId)->where('system_key', 'fx_gain_loss')->delete();
}

/**
 * @return list<Account>
 */
function fxSystemAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::FX_GAIN_LOSS)
        ->orderBy('code')
        ->get()
        ->all();
}

describe('the constant and the version', function (): void {
    it('exposes the FX gain/loss system key', function (): void {
        expect(Account::FX_GAIN_LOSS)->toBe('fx_gain_loss');
    });

    it('bumps the chart template version', function (): void {
        expect(ChartTemplate::VERSION)->toBe('2026.09-lk-sme-5');
    });
});

describe('a newly provisioned company', function (): void {
    it('receives exactly one FX gain/loss system account, keyed Income/credit-normal', function (): void {
        $accounts = fxSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $fx = $accounts[0];

        expect($fx->system_key)->toBe('fx_gain_loss')
            ->and($fx->is_system)->toBeTrue()
            ->and($fx->is_postable)->toBeTrue()
            ->and($fx->is_active)->toBeTrue()
            ->and($fx->type)->toBe(AccountType::Income)
            ->and($fx->normal_balance->value)->toBe('credit')
            ->and($fx->code)->toBe('4910')
            ->and($fx->name)->toBe('Foreign Exchange Gain/(Loss)')
            ->and($fx->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('receives one even when it declined the starter chart', function (): void {
        $bare = $this->createWorkspace('bare')['company'];

        $this->template->ensureSystemAccounts($bare);

        $accounts = fxSystemAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Income)
            ->and($accounts[0]->code)->toBe('4910');
    });

    it('is given no second one by repeated provisioning', function (): void {
        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(fxSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });
});

describe('the backfill (create, not stamp)', function (): void {
    it('creates a keyed FX account for a company that has none, under 4000', function (): void {
        makeLegacyNoFx($this->company->getKey());
        expect(fxSystemAccounts($this->company->getKey()))->toHaveCount(0);

        $parent = Account::query()->forCompany($this->company->getKey())->where('code', '4000')->firstOrFail();

        fxBackfill()->up();

        $accounts = fxSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->code)->toBe('4910')
            ->and($accounts[0]->type)->toBe(AccountType::Income)
            ->and($accounts[0]->normal_balance->value)->toBe('credit')
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and((string) $accounts[0]->parent_id)->toBe((string) $parent->getKey())
            ->and($accounts[0]->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('is idempotent — a second run creates nothing', function (): void {
        makeLegacyNoFx($this->company->getKey());

        $migration = fxBackfill();
        $migration->up();
        $countAfterFirst = Account::query()->forCompany($this->company->getKey())->count();

        $migration->up();
        fxBackfill()->up();

        expect(Account::query()->forCompany($this->company->getKey())->count())->toBe($countAfterFirst)
            ->and(fxSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('takes the next free code when 4910 is already used by a hand-numbered account', function (): void {
        makeLegacyNoFx($this->company->getKey());

        $parent = Account::query()->forCompany($this->company->getKey())->where('code', '4000')->firstOrFail();
        DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'parent_id' => $parent->getKey(),
            'code' => '4910',
            'name' => 'Some Other Income',
            'type' => 'income',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'is_system' => false,
            'system_key' => null,
            'is_active' => true,
            'archived_at' => null,
            'template_version' => null,
            'sort_order' => 4910,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        fxBackfill()->up();

        $accounts = fxSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->code)->toBe('4910-1')
            ->and($accounts[0]->system_key)->toBe('fx_gain_loss');
    });

    it('attaches to NULL parent when 4000 is not resolvable', function (): void {
        makeLegacyNoFx($this->company->getKey());

        DB::table('accounts')->where('company_id', $this->company->getKey())->where('code', '4000')
            ->update(['code' => '4000-INC']);

        fxBackfill()->up();

        expect(fxSystemAccounts($this->company->getKey())[0]->parent_id)->toBeNull();
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        makeLegacyNoFx($other['company']->getKey());

        $this->withinTenant($this->acme['tenant']);
        makeLegacyNoFx($this->company->getKey());

        fxBackfill()->up();

        $keyed = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('company_id', [$this->company->getKey(), $other['company']->getKey()])
            ->where('system_key', 'fx_gain_loss')
            ->where('is_system', true)
            ->pluck('company_id')
            ->sort()
            ->values()
            ->all());

        expect($keyed)->toBe(collect([$this->company->getKey(), $other['company']->getKey()])->sort()->values()->all());
    });

    it('assertBypassEffective trips when called outside RowLevelSecurity::bypass()', function (): void {
        $migration = fxBackfill();
        $method = new ReflectionMethod($migration, 'assertBypassEffective');
        $method->setAccessible(true);

        expect(fn () => $method->invoke($migration))->toThrow(RuntimeException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('accounts'),
        'Row level security is not in force for the connecting role; the guard cannot trip.',
    );

    it('assertNothingLeftBehind trips when a company is left without the account', function (): void {
        makeLegacyNoFx($this->company->getKey());

        $migration = fxBackfill();
        $method = new ReflectionMethod($migration, 'assertNothingLeftBehind');
        $method->setAccessible(true);

        expect(fn () => RowLevelSecurity::bypass(fn () => $method->invoke($migration)))
            ->toThrow(RuntimeException::class);
    });

    it('down() deletes only a postings-free account it created', function (): void {
        makeLegacyNoFx($this->company->getKey());

        $migration = fxBackfill();
        $migration->up();
        expect(fxSystemAccounts($this->company->getKey()))->toHaveCount(1);

        $migration->down();
        expect(fxSystemAccounts($this->company->getKey()))->toHaveCount(0);
    });
});
