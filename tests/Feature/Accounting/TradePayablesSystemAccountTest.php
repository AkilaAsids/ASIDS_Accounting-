<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trade Payables as a system account, and the backfill that gives it to companies built before it existed.
 *
 * Stage 1 of Wave 7 (ADR 0019 §A0). Wave 7 makes `2110 Trade Payables` the account every posted bill credits,
 * resolved by system key — never by code, because a company may renumber its chart freely. That is a new system
 * key, provisioned two ways depending on when the company was created, which is where the risk is.
 *
 * A company created from here on gets it from the template (VERSION bumped to `2026.09-lk-sme-3`, ADR §A0.2). A
 * company created from an earlier template already has a keyless `2110 Trade Payables`, and
 * `ensureSystemAccounts()` would not recognise it: that method provisions a missing key by *creating* an account
 * and its collision helper takes the next free code, so it would create `2110-1 Trade Payables` beside the
 * original — two payable accounts, the new one taking every future bill while the old one holds the history, and
 * nothing reporting it.
 *
 * TWO PRIOR TEMPLATE VERSIONS, NOT ONE
 * ------------------------------------
 * Unlike Trade Receivables — which the current template already keyed at provisioning, so only `2026.02-lk-sme-1`
 * needed the backfill — neither `2026.02-lk-sme-1` nor `2026.08-lk-sme-2` ever stamped `2110` (ADR §A0.3). So the
 * backfill covers *both* prior versions, and both are exercised here.
 *
 * This file mirrors `tests/Feature/Accounting/TradeReceivablesSystemAccountTest.php` with `2110`/Liability in
 * place of `1130`/Asset. The first backfill test provokes the duplicate so the migration is justified by
 * demonstration; the rest prove it prevents that without touching anything else.
 *
 * RED expectation before Stage 1 lands: `Account::TRADE_PAYABLES` does not exist and the backfill migration file
 * is absent, so every test errors on the missing constant or the missing `require`.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->template = app(ChartTemplateService::class);

    $this->template->apply($this->company);
});

/**
 * The migration under test, loaded the way Laravel loads it.
 */
function tradePayablesBackfill(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'src/Core/Accounting/Database/Migrations/2026_09_02_000001_stamp_trade_payables_system_key.php'
    );

    return $migration;
}

/**
 * Returns a company's Trade Payables account to the keyless shape an earlier template left it in.
 *
 * There is no other way to reach that state: every company these tests can create is built from the current
 * template, which stamps the key on the way in once Stage 1 lands. Reverting it is what makes a legacy company
 * available to test against, and the version is a parameter because the backfill covers *both* prior templates.
 */
function makeLegacyPayable(string $companyId, string $templateVersion = '2026.08-lk-sme-2'): string
{
    $id = (string) Account::query()->forCompany($companyId)->where('code', '2110')->value('id');

    DB::table('accounts')->where('id', $id)->update([
        'system_key' => null,
        'is_system' => false,
        'template_version' => $templateVersion,
    ]);

    return $id;
}

/**
 * Every account carrying the trade payables key in the given company.
 *
 * @return list<Account>
 */
function payableSystemAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::TRADE_PAYABLES)
        ->orderBy('code')
        ->get()
        ->all();
}

describe('the constant and the version', function (): void {
    it('exposes the trade payables system key', function (): void {
        // Resolved by key, never by code — the whole reason the key exists (ADR §A0.1, mirror `Account::71`).
        expect(Account::TRADE_PAYABLES)->toBe('trade_payables');
    });

    it('bumps the chart template version so existing companies can be found', function (): void {
        // ADR §A0.2, confirmed at Gate 2: 2026.08-lk-sme-2 → 2026.09-lk-sme-3. A correction that must reach the
        // companies built before it needs the version to change, exactly as the receivables key did.
        expect(ChartTemplate::VERSION)->toBe('2026.09-lk-sme-3');
    });
});

describe('a newly provisioned company', function (): void {
    it('receives exactly one trade payables system account', function (): void {
        $accounts = payableSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $payable = $accounts[0];

        expect($payable->system_key)->toBe(Account::TRADE_PAYABLES)
            ->and($payable->system_key)->toBe('trade_payables')
            ->and($payable->is_system)->toBeTrue()
            // Postable, or the bill's credit has nowhere to land — a heading takes no journal lines.
            ->and($payable->is_postable)->toBeTrue()
            ->and($payable->is_active)->toBeTrue()
            ->and($payable->type)->toBe(AccountType::Liability)
            // A payable is a credit-normal liability, which is the side a bill's Cr Trade Payables lands on.
            ->and($payable->normal_balance->value)->toBe('credit')
            ->and($payable->company_id)->toBe($this->company->getKey())
            ->and($payable->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($payable->code)->toBe('2110')
            ->and($payable->name)->toBe('Trade Payables')
            ->and($payable->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('is given no second one by repeated provisioning', function (): void {
        $before = Account::query()->forCompany($this->company->getKey())->count();

        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(payableSystemAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(Account::query()->forCompany($this->company->getKey())->count())->toBe($before);
    });

    it('receives one even when it declined the starter chart', function (): void {
        // A company with no chart at all still has to be billable, which is why `2110` is in
        // `requiredSystemAccounts()` and not only in the template (ADR §A0.2).
        $bare = $this->createWorkspace('bare')['company'];

        $this->template->ensureSystemAccounts($bare);

        $accounts = payableSystemAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Liability)
            ->and($accounts[0]->code)->toBe('2110')
            ->and($accounts[0]->company_id)->toBe($bare->getKey());
    });
});

describe('the backfill', function (): void {
    it('is necessary, because provisioning alone would duplicate the account', function (string $priorVersion): void {
        makeLegacyPayable($this->company->getKey(), $priorVersion);

        // No migration run. The outcome the migration exists to prevent, demonstrated rather than described —
        // and if `ensureSystemAccounts()` ever stops behaving this way, this test says so.
        $created = $this->template->ensureSystemAccounts($this->company);

        expect($created)->toHaveCount(1)
            ->and($created[0]->code)->toBe('2110-1')
            ->and(Account::query()->forCompany($this->company->getKey())->where('name', 'Trade Payables')->count())
            ->toBe(2);
    })->with(['2026.02-lk-sme-1', '2026.08-lk-sme-2']);

    it('stamps a legacy account from either prior template in place, without changing its identity', function (string $priorVersion): void {
        $id = makeLegacyPayable($this->company->getKey(), $priorVersion);

        $before = DB::table('accounts')->where('id', $id)->first();

        tradePayablesBackfill()->up();

        $after = DB::table('accounts')->where('id', $id)->first();

        expect($after->id)->toBe($before->id)
            ->and($after->code)->toBe('2110')
            ->and($after->name)->toBe('Trade Payables')
            ->and($after->type)->toBe($before->type)
            ->and($after->parent_id)->toBe($before->parent_id)
            ->and($after->is_postable)->toBe($before->is_postable)
            ->and($after->created_at)->toBe($before->created_at)
            // Left at the version that created it. Rewriting the field would falsify the account's history to
            // record a migration that only stamped a key.
            ->and($after->template_version)->toBe($priorVersion)
            ->and($after->system_key)->toBe('trade_payables')
            // Both, or `accounts_system_key_check` refuses the row — and `is_system` is what stops the account
            // being deleted or reclassified from now on.
            ->and($after->is_system)->toBeTrue();
    })->with(['2026.02-lk-sme-1', '2026.08-lk-sme-2']);

    it('creates nothing and deletes nothing', function (): void {
        makeLegacyPayable($this->company->getKey());

        $before = Account::query()->forCompany($this->company->getKey())->pluck('id')->sort()->values()->all();

        tradePayablesBackfill()->up();

        expect(Account::query()->forCompany($this->company->getKey())->pluck('id')->sort()->values()->all())
            ->toBe($before);
    });

    it('is safe to run more than once', function (): void {
        $id = makeLegacyPayable($this->company->getKey());

        $migration = tradePayablesBackfill();
        $migration->up();

        $afterFirst = DB::table('accounts')->where('id', $id)->first();

        $migration->up();
        tradePayablesBackfill()->up();

        $afterThird = DB::table('accounts')->where('id', $id)->first();

        // Not merely "still correct" — untouched. The `system_key IS NULL` guard means the second and third runs
        // match no rows at all, so even `updated_at` does not move.
        expect((array) $afterThird)->toBe((array) $afterFirst)
            ->and(payableSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('leaves provisioning with nothing to do afterwards', function (): void {
        makeLegacyPayable($this->company->getKey());

        tradePayablesBackfill()->up();

        // The point of the whole exercise: the duplicate demonstrated in the first test does not happen.
        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(Account::query()->forCompany($this->company->getKey())->where('code', '2110-1')->exists())
            ->toBeFalse()
            ->and(payableSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        $otherId = makeLegacyPayable($other['company']->getKey(), '2026.02-lk-sme-1');

        $this->withinTenant($this->acme['tenant']);
        $acmeId = makeLegacyPayable($this->company->getKey());

        tradePayablesBackfill()->up();

        // A migration has no tenant, so without the bypass this statement would match nothing on a FORCED table
        // and report success. Both tenants stamped is the proof it did not (ADR §A0.3 — the backfill-silence risk).
        $stamped = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('id', [$acmeId, $otherId])
            ->where('system_key', 'trade_payables')
            ->where('is_system', true)
            ->pluck('id')
            ->sort()
            ->values()
            ->all());

        expect($stamped)->toBe(collect([$acmeId, $otherId])->sort()->values()->all());
    });

    it('leaves a hand-made 2110 alone', function (): void {
        $id = makeLegacyPayable($this->company->getKey());

        // A company that never used the starter chart and numbered its own account 2110. Nobody promised what
        // that account means, so the migration has no business claiming it.
        DB::table('accounts')->where('id', $id)->update([
            'template_version' => null,
            'name' => 'Creditors control',
        ]);

        tradePayablesBackfill()->up();

        $after = DB::table('accounts')->where('id', $id)->first();

        expect($after->system_key)->toBeNull()
            ->and($after->is_system)->toBeFalse()
            ->and($after->name)->toBe('Creditors control');
    });

    it('leaves a company that already holds the key alone', function (): void {
        $legacyId = makeLegacyPayable($this->company->getKey());

        // Someone renumbered payables to 2120 and the key went with it. The old 2110 is now something else's
        // history, and stamping it would collide with `accounts_company_system_key_unique`.
        $other = Account::query()->forCompany($this->company->getKey())->where('code', '2120')->firstOrFail();

        DB::table('accounts')->where('id', $other->getKey())->update([
            'system_key' => 'trade_payables',
            'is_system' => true,
        ]);

        tradePayablesBackfill()->up();

        expect(DB::table('accounts')->where('id', $legacyId)->value('system_key'))->toBeNull()
            ->and(payableSystemAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(payableSystemAccounts($this->company->getKey())[0]->getKey())->toBe($other->getKey());
    });

    it('leaves a soft-deleted account alone', function (): void {
        $id = makeLegacyPayable($this->company->getKey());

        DB::table('accounts')->where('id', $id)->update(['deleted_at' => now()]);

        tradePayablesBackfill()->up();

        expect(DB::table('accounts')->where('id', $id)->value('system_key'))->toBeNull();
    });

    it('leaves an account of the wrong type alone', function (): void {
        $id = makeLegacyPayable($this->company->getKey());

        // A company that reclassified its 2110. A payable is a liability; if this row is something else, the
        // migration is not the place to argue (ADR §A0.3 guards on `type = 'liability'`).
        DB::table('accounts')->where('id', $id)->update(['type' => 'expense', 'normal_balance' => 'debit']);

        tradePayablesBackfill()->up();

        expect(DB::table('accounts')->where('id', $id)->value('system_key'))->toBeNull();
    });

    it('reverses only what it stamped', function (): void {
        $legacyId = makeLegacyPayable($this->company->getKey());

        $fresh = $this->createWorkspace('fresh');
        $this->withinTenant($fresh['tenant']);
        $this->template->apply($fresh['company']);
        $freshId = (string) Account::query()->forCompany($fresh['company']->getKey())->where('code', '2110')->value('id');

        $this->withinTenant($this->acme['tenant']);

        $migration = tradePayablesBackfill();
        $migration->up();
        $migration->down();

        $rows = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('id', [$legacyId, $freshId])
            ->pluck('system_key', 'id')
            ->all());

        // The legacy account goes back to keyless. The company provisioned from the current template keeps its
        // key, because rolling this migration back must not un-provision a company that never needed it.
        expect($rows[$legacyId])->toBeNull()
            ->and($rows[$freshId])->toBe('trade_payables');
    });
});
