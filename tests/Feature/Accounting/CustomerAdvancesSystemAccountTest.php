<?php

declare(strict_types=1);

use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `2180 Customer Advances`: a fourth system account, and the backfill that creates it for companies built
 * before it existed — ADR 0016 §A, Stage 1.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API that ADR 0016 §A pins down:
 *
 *   - `Account::CUSTOMER_ADVANCES === 'customer_advances'` — the fourth system key beside RETAINED_EARNINGS,
 *     OPENING_BALANCE_EQUITY and TRADE_RECEIVABLES.
 *   - `2180 Customer Advances`, type Liability, normal balance Credit (derived), a required system account in
 *     BOTH `ChartTemplate::accounts()` and `requiredSystemAccounts()`.
 *   - `ChartTemplate::VERSION` bumped to `2026.08-lk-sme-3` (Gate-2 APPROVED).
 *   - A raw-SQL RLS-bypass migration that CREATES (not stamps) the account for every existing company,
 *     mirroring `2026_03_05_000003`'s "statement about the past" discipline (Gate-2 decision (a)).
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * The system key does not exist, so `Account::CUSTOMER_ADVANCES` errors with an undefined-constant fatal; the
 * template does not carry `2180`, so a freshly provisioned company holds zero customer-advances accounts; and
 * the backfill migration file does not exist, so `customerAdvancesBackfill()` fails with a clear message. Each
 * failure names an absent Stage-1 decision, never a broken fixture — setup runs through the shipped
 * `ChartTemplateService`.
 *
 * Deliberately mirrors `TradeReceivablesSystemAccountTest`, the nearest precedent, so the two read alike.
 * The one material difference the ADR flags: Trade Receivables already existed as `1130` and only needed
 * *stamping*; Customer Advances has never existed in any template, so a legacy company needs it *created*.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->template = app(ChartTemplateService::class);

    $this->template->apply($this->company);
});

/**
 * The backfill migration under test, located by filename rather than hard-coded — the engineer chooses the
 * exact timestamp, but ADR 0016 §A requires the account name in it. Fails RED with an actionable message
 * until the migration lands.
 */
function customerAdvancesBackfill(): Migration
{
    $matches = glob(base_path('src/Core/Accounting/Database/Migrations/*customer_advances*.php'));

    if ($matches === false || $matches === []) {
        test()->fail(
            'No Customer Advances backfill migration found under '
            .'src/Core/Accounting/Database/Migrations. ADR 0016 §A requires a raw-SQL, RLS-bypass migration '
            .'that CREATES 2180 Customer Advances for every existing company (filename must contain '
            .'"customer_advances").'
        );
    }

    /** @var Migration $migration */
    $migration = require $matches[0];

    return $migration;
}

/**
 * Every active account carrying the customer-advances key in the given company.
 *
 * @return list<Account>
 */
function customerAdvancesAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::CUSTOMER_ADVANCES)
        ->orderBy('code')
        ->get()
        ->all();
}

/**
 * Removes a company's Customer Advances account entirely, simulating a company provisioned before the account
 * ever existed. Unlike Trade Receivables — which was only re-keyed — the legacy state here is the account's
 * complete absence, because it shipped in no earlier template.
 */
function makeLegacyWithoutAdvances(string $companyId): void
{
    DB::table('accounts')
        ->where('company_id', $companyId)
        ->where('system_key', Account::CUSTOMER_ADVANCES)
        ->delete();
}

describe('the system key', function (): void {
    it('is a fourth key with the value ADR 0016 fixes', function (): void {
        // The whole feature resolves the account by this key, never by code 2180 — a company may renumber.
        expect(Account::CUSTOMER_ADVANCES)->toBe('customer_advances');
    });
});

describe('the chart template', function (): void {
    it('advances its version for the added account', function (): void {
        // ChartTemplate::VERSION's own contract: bump on any addition. Gate-2 APPROVED the target value.
        expect(ChartTemplate::VERSION)->toBe('2026.08-lk-sme-4');
    });

    it('lists 2180 Customer Advances as a required system account', function (): void {
        $required = collect(ChartTemplate::requiredSystemAccounts())
            ->firstWhere('system', Account::CUSTOMER_ADVANCES);

        expect($required)->not->toBeNull()
            ->and($required['code'])->toBe('2180')
            ->and($required['name'])->toBe('Customer Advances')
            ->and($required['type'])->toBe(AccountType::Liability)
            ->and($required['postable'])->toBeTrue();
    });
});

describe('a newly provisioned company', function (): void {
    it('receives exactly one Customer Advances system account, correctly classified', function (): void {
        $accounts = customerAdvancesAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $advances = $accounts[0];

        expect($advances->system_key)->toBe(Account::CUSTOMER_ADVANCES)
            ->and($advances->system_key)->toBe('customer_advances')
            ->and($advances->is_system)->toBeTrue()
            // Postable, or the remainder credit has nowhere to land — a heading takes no journal lines.
            ->and($advances->is_postable)->toBeTrue()
            ->and($advances->is_active)->toBeTrue()
            ->and($advances->type)->toBe(AccountType::Liability)
            // Derived from the type on save; the credit side of the receipt split depends on it.
            ->and($advances->normal_balance)->toBe(NormalBalance::Credit)
            ->and($advances->code)->toBe('2180')
            ->and($advances->name)->toBe('Customer Advances')
            ->and($advances->company_id)->toBe($this->company->getKey())
            ->and($advances->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($advances->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('files 2180 under Current Liabilities (2100), not renumbering any existing leaf', function (): void {
        $advances = customerAdvancesAccounts($this->company->getKey())[0];
        $parent = Account::query()->whereKey($advances->parent_id)->first();

        expect($parent)->not->toBeNull()
            ->and($parent->code)->toBe('2100')
            ->and($parent->name)->toBe('Current Liabilities')
            // The pre-existing 2170 statutory leaf is untouched — 2180 is a free code, not a renumber.
            ->and(Account::query()->forCompany($this->company->getKey())->where('code', '2170')->value('name'))
            ->toBe('ETF Payable');
    });

    it('resolves the account by key, the way every posting will', function (): void {
        $resolved = Account::query()
            ->forCompany($this->company->getKey())
            ->withSystemKey(Account::CUSTOMER_ADVANCES)
            ->first();

        expect($resolved)->not->toBeNull()
            ->and($resolved->code)->toBe('2180')
            ->and($resolved->acceptsPostings())->toBeTrue();
    });

    it('is given no second one by repeated provisioning', function (): void {
        $before = Account::query()->forCompany($this->company->getKey())->count();

        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(customerAdvancesAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(Account::query()->forCompany($this->company->getKey())->count())->toBe($before);
    });

    it('receives one even when it declined the starter chart', function (): void {
        // A company with no chart at all must still be able to hold an overpayment, which is why the key is in
        // requiredSystemAccounts() and not only in the template.
        $bare = $this->createWorkspace('bare')['company'];

        $this->template->ensureSystemAccounts($bare);

        $accounts = customerAdvancesAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Liability)
            ->and($accounts[0]->company_id)->toBe($bare->getKey());
    });
});

describe('the backfill migration', function (): void {
    it('creates the account for a company that predates it', function (): void {
        makeLegacyWithoutAdvances($this->company->getKey());

        expect(customerAdvancesAccounts($this->company->getKey()))->toHaveCount(0);

        customerAdvancesBackfill()->up();

        $accounts = customerAdvancesAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->is_active)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Liability)
            ->and($accounts[0]->normal_balance)->toBe(NormalBalance::Credit)
            // is_system and system_key must be written together, or accounts_system_key_check refuses the row —
            // the exact trap 2026_03_05_000003 documents.
            ->and($accounts[0]->system_key)->toBe('customer_advances');
    });

    it('leaves every company holding exactly one afterwards, the "nothing left behind" guarantee', function (): void {
        makeLegacyWithoutAdvances($this->company->getKey());

        customerAdvancesBackfill()->up();

        // A company whose first overpayment would otherwise fail months later is caught now.
        expect(customerAdvancesAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('is safe to run more than once', function (): void {
        makeLegacyWithoutAdvances($this->company->getKey());

        $migration = customerAdvancesBackfill();
        $migration->up();
        $migration->up();
        customerAdvancesBackfill()->up();

        expect(customerAdvancesAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        makeLegacyWithoutAdvances($other['company']->getKey());

        $this->withinTenant($this->acme['tenant']);
        makeLegacyWithoutAdvances($this->company->getKey());

        customerAdvancesBackfill()->up();

        // A migration has no tenant, so without the bypass this would touch nothing on a FORCED table and
        // report success. Both tenants provisioned is the proof it did not.
        $provisioned = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('company_id', [$this->company->getKey(), $other['company']->getKey()])
            ->where('system_key', 'customer_advances')
            ->where('is_system', true)
            ->whereNull('deleted_at')
            ->pluck('company_id')
            ->sort()
            ->values()
            ->all());

        expect($provisioned)->toBe(
            collect([$this->company->getKey(), $other['company']->getKey()])->sort()->values()->all()
        );
    });

    it('does not create a second account for a company that already holds the key', function (): void {
        // The company still has its template-provisioned 2180. A create-not-stamp migration must skip it,
        // or accounts_company_system_key_unique would refuse the duplicate.
        customerAdvancesBackfill()->up();

        expect(customerAdvancesAccounts($this->company->getKey()))->toHaveCount(1);
    });
});
