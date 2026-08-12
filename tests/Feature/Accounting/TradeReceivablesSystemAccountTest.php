<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trade Receivables as a system account, and the backfill that gives it to companies built before it existed.
 *
 * Milestone 5 made Trade Receivables the account every sales invoice debits unless the customer names one of
 * its own. That is a new system key, and a new system key is provisioned two different ways depending on when
 * the company was created — which is where the risk is.
 *
 * A company created from here on gets it from the template. A company created from template
 * `2026.02-lk-sme-1` already has `1130 Trade Receivables`, and `ensureSystemAccounts()` would not recognise it:
 * that method provisions a missing key by *creating* an account, and its collision helper takes the next free
 * code. So it would create `1130-1 Trade Receivables` beside the original — two receivable accounts, the new
 * one taking every future invoice while the old one holds the entire history, and nothing anywhere reporting
 * that it happened.
 *
 * The first test in the backfill group provokes exactly that outcome, so the migration is justified by
 * demonstration rather than by assertion. The rest prove the migration prevents it without touching anything
 * else: no account created, no id moved, no code or name rewritten, no customer's chosen receivable account
 * disturbed.
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
function tradeReceivablesBackfill(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'src/Core/Accounting/Database/Migrations/2026_03_05_000003_stamp_trade_receivables_system_key.php'
    );

    return $migration;
}

/**
 * Returns a company's Trade Receivables account to the shape template `2026.02-lk-sme-1` left it in.
 *
 * There is no other way to reach that state: every company these tests can create is built from the current
 * template, which stamps the key on the way in. Reverting it is what makes a legacy company available to test
 * against, and the three columns below are exactly the three the new template changed.
 */
function makeLegacyReceivable(string $companyId): string
{
    $id = (string) Account::query()->forCompany($companyId)->where('code', '1130')->value('id');

    DB::table('accounts')->where('id', $id)->update([
        'system_key' => null,
        'is_system' => false,
        'template_version' => '2026.02-lk-sme-1',
    ]);

    return $id;
}

/**
 * Every account carrying the trade receivables key in the given company.
 *
 * @return list<Account>
 */
function receivableSystemAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::TRADE_RECEIVABLES)
        ->orderBy('code')
        ->get()
        ->all();
}

describe('a newly provisioned company', function (): void {
    it('receives exactly one trade receivables system account', function (): void {
        $accounts = receivableSystemAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $receivable = $accounts[0];

        expect($receivable->system_key)->toBe(Account::TRADE_RECEIVABLES)
            ->and($receivable->system_key)->toBe('trade_receivables')
            ->and($receivable->is_system)->toBeTrue()
            // Postable, or the invoice's debit has nowhere to land — a heading takes no journal lines.
            ->and($receivable->is_postable)->toBeTrue()
            ->and($receivable->is_active)->toBeTrue()
            ->and($receivable->type)->toBe(AccountType::Asset)
            ->and($receivable->company_id)->toBe($this->company->getKey())
            ->and($receivable->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($receivable->code)->toBe('1130')
            ->and($receivable->name)->toBe('Trade Receivables')
            ->and($receivable->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('is given no second one by repeated provisioning', function (): void {
        $before = Account::query()->forCompany($this->company->getKey())->count();

        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(receivableSystemAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(Account::query()->forCompany($this->company->getKey())->count())->toBe($before);
    });

    it('receives one even when it declined the starter chart', function (): void {
        // A company with no chart at all still has to be invoiceable, which is why the key is in
        // `requiredSystemAccounts()` and not only in the template.
        $bare = $this->createWorkspace('bare')['company'];

        $created = $this->template->ensureSystemAccounts($bare);

        expect($created)->toHaveCount(count(ChartTemplate::requiredSystemAccounts()));

        $accounts = receivableSystemAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Asset)
            ->and($accounts[0]->company_id)->toBe($bare->getKey());
    });
});

describe('the backfill', function (): void {
    it('is necessary, because provisioning alone would duplicate the account', function (): void {
        makeLegacyReceivable($this->company->getKey());

        // No migration run. This is the outcome the migration exists to prevent, demonstrated rather than
        // described — and if `ensureSystemAccounts()` ever stops behaving this way, this test says so.
        $created = $this->template->ensureSystemAccounts($this->company);

        expect($created)->toHaveCount(1)
            ->and($created[0]->code)->toBe('1130-1')
            ->and(Account::query()->forCompany($this->company->getKey())->where('name', 'Trade Receivables')->count())
            ->toBe(2);
    });

    it('stamps the legacy account in place, without changing its identity', function (): void {
        $id = makeLegacyReceivable($this->company->getKey());

        $before = DB::table('accounts')->where('id', $id)->first();

        tradeReceivablesBackfill()->up();

        $after = DB::table('accounts')->where('id', $id)->first();

        expect($after->id)->toBe($before->id)
            ->and($after->code)->toBe('1130')
            ->and($after->name)->toBe('Trade Receivables')
            ->and($after->type)->toBe($before->type)
            ->and($after->parent_id)->toBe($before->parent_id)
            ->and($after->is_postable)->toBe($before->is_postable)
            ->and($after->created_at)->toBe($before->created_at)
            // Left at the version that actually created it. Rewriting the field would falsify the account's
            // history to record a migration that only stamped a key.
            ->and($after->template_version)->toBe('2026.02-lk-sme-1')
            ->and($after->system_key)->toBe('trade_receivables')
            // Both, or `accounts_system_key_check` refuses the row — and `is_system` is what stops the account
            // being deleted or reclassified from now on.
            ->and($after->is_system)->toBeTrue();
    });

    it('creates nothing and deletes nothing', function (): void {
        makeLegacyReceivable($this->company->getKey());

        $before = Account::query()->forCompany($this->company->getKey())->pluck('id')->sort()->values()->all();

        tradeReceivablesBackfill()->up();

        expect(Account::query()->forCompany($this->company->getKey())->pluck('id')->sort()->values()->all())
            ->toBe($before);
    });

    it('is safe to run more than once', function (): void {
        $id = makeLegacyReceivable($this->company->getKey());

        $migration = tradeReceivablesBackfill();
        $migration->up();

        $afterFirst = DB::table('accounts')->where('id', $id)->first();

        $migration->up();
        tradeReceivablesBackfill()->up();

        $afterThird = DB::table('accounts')->where('id', $id)->first();

        // Not merely "still correct" — untouched. The `system_key IS NULL` guard means the second and third
        // runs match no rows at all, so even `updated_at` does not move.
        expect((array) $afterThird)->toBe((array) $afterFirst)
            ->and(receivableSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('leaves provisioning with nothing to do afterwards', function (): void {
        makeLegacyReceivable($this->company->getKey());

        tradeReceivablesBackfill()->up();

        // The point of the whole exercise: the duplicate demonstrated in the first test does not happen.
        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(Account::query()->forCompany($this->company->getKey())->where('code', '1130-1')->exists())
            ->toBeFalse()
            ->and(receivableSystemAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        $otherId = makeLegacyReceivable($other['company']->getKey());

        $this->withinTenant($this->acme['tenant']);
        $acmeId = makeLegacyReceivable($this->company->getKey());

        tradeReceivablesBackfill()->up();

        // A migration has no tenant, so without the bypass this statement would match nothing on a FORCED
        // table and report success. Both tenants stamped is the proof it did not.
        $stamped = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('id', [$acmeId, $otherId])
            ->where('system_key', 'trade_receivables')
            ->where('is_system', true)
            ->pluck('id')
            ->sort()
            ->values()
            ->all());

        expect($stamped)->toBe(collect([$acmeId, $otherId])->sort()->values()->all());
    });

    it('leaves a hand-made 1130 alone', function (): void {
        $id = makeLegacyReceivable($this->company->getKey());

        // A company that never used the starter chart and numbered its own account 1130. Nobody promised what
        // that account means, so the migration has no business claiming it.
        DB::table('accounts')->where('id', $id)->update([
            'template_version' => null,
            'name' => 'Debtors control',
        ]);

        tradeReceivablesBackfill()->up();

        $after = DB::table('accounts')->where('id', $id)->first();

        expect($after->system_key)->toBeNull()
            ->and($after->is_system)->toBeFalse()
            ->and($after->name)->toBe('Debtors control');
    });

    it('leaves a company that already holds the key alone', function (): void {
        $legacyId = makeLegacyReceivable($this->company->getKey());

        // Someone renumbered receivables to 1135 and the key went with it. The old 1130 is now something else's
        // history, and stamping it would collide with `accounts_company_system_key_unique`.
        $other = Account::query()->forCompany($this->company->getKey())->where('code', '1140')->firstOrFail();

        DB::table('accounts')->where('id', $other->getKey())->update([
            'system_key' => 'trade_receivables',
            'is_system' => true,
        ]);

        tradeReceivablesBackfill()->up();

        expect(DB::table('accounts')->where('id', $legacyId)->value('system_key'))->toBeNull()
            ->and(receivableSystemAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(receivableSystemAccounts($this->company->getKey())[0]->getKey())->toBe($other->getKey());
    });

    it('leaves a soft-deleted account alone', function (): void {
        $id = makeLegacyReceivable($this->company->getKey());

        DB::table('accounts')->where('id', $id)->update(['deleted_at' => now()]);

        tradeReceivablesBackfill()->up();

        expect(DB::table('accounts')->where('id', $id)->value('system_key'))->toBeNull();
    });

    it('does not disturb a customer that names its own receivable account', function (): void {
        $legacyId = makeLegacyReceivable($this->company->getKey());

        $chosen = Account::query()->forCompany($this->company->getKey())->where('code', '1140')->firstOrFail();

        $customer = app(CustomerService::class)->create($this->company, new CustomerData(
            name: 'Silva Traders',
            code: 'SILVA',
            receivableAccountId: (string) $chosen->getKey(),
        ));

        tradeReceivablesBackfill()->up();

        // Ids are never moved, so every pointer at an account — a customer's chosen receivable, a posted
        // journal line — still resolves to the same row afterwards.
        expect((string) $customer->fresh()->receivable_account_id)->toBe((string) $chosen->getKey())
            ->and(DB::table('accounts')->where('id', $legacyId)->value('system_key'))->toBe('trade_receivables');
    });

    it('reverses only what it stamped', function (): void {
        $legacyId = makeLegacyReceivable($this->company->getKey());

        $fresh = $this->createWorkspace('fresh');
        $this->withinTenant($fresh['tenant']);
        $this->template->apply($fresh['company']);
        $freshId = (string) Account::query()->forCompany($fresh['company']->getKey())->where('code', '1130')->value('id');

        $this->withinTenant($this->acme['tenant']);

        $migration = tradeReceivablesBackfill();
        $migration->up();
        $migration->down();

        $rows = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('id', [$legacyId, $freshId])
            ->pluck('system_key', 'id')
            ->all());

        // The legacy account goes back to keyless. The company provisioned from the current template keeps its
        // key, because rolling this migration back must not un-provision a company that never needed it.
        expect($rows[$legacyId])->toBeNull()
            ->and($rows[$freshId])->toBe('trade_receivables');
    });
});
