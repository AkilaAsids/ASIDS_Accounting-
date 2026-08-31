<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `1180 WHT Receivable`: a fifth system account, and the backfill that creates it for companies built before it
 * existed — ADR 0017 §A, Stage 1.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API that ADR 0017 §A pins down:
 *
 *   - `Account::WHT_RECEIVABLE === 'wht_receivable'` — a fifth system key beside RETAINED_EARNINGS,
 *     OPENING_BALANCE_EQUITY, TRADE_RECEIVABLES and CUSTOMER_ADVANCES.
 *   - `1180 WHT Receivable`, type Asset, normal balance Debit (derived), a required system account in BOTH
 *     `ChartTemplate::accounts()` (right after `1170 Input VAT Recoverable`) and `requiredSystemAccounts()`.
 *   - `ChartTemplate::VERSION` bumped to `2026.08-lk-sme-4` (Gate-2 APPROVED).
 *   - A raw-SQL, RLS-bypass migration that CREATES (not stamps) the account for every existing company,
 *     mirroring the `2180 Customer Advances` backfill's "statement about the past" discipline (Gate-2 fork (b)).
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * The system key does not exist, so `Account::WHT_RECEIVABLE` errors with an undefined-constant fatal; the
 * template does not carry `1180`, so a freshly provisioned company holds zero WHT-Receivable accounts and the
 * VERSION assertion sees the un-bumped `…-3`; the backfill migration file does not exist, so
 * `whtReceivableBackfill()` fails with an actionable message. Each failure names an absent Stage-1 decision,
 * never a broken fixture — setup runs through the shipped `ChartTemplateService`.
 *
 * Deliberately mirrors `CustomerAdvancesSystemAccountTest`, the nearest precedent (same create-not-stamp
 * pattern), so the two read alike. Like Customer Advances, WHT Receivable has never existed in any template, so
 * a legacy company needs it *created*, not merely *stamped* — the material difference from Trade Receivables.
 * WHT's asset/debit classification is the only sign-convention change from that liability/credit precedent.
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
 * exact timestamp, but ADR 0017 §A requires the account name in it. Fails RED with an actionable message until
 * the migration lands.
 */
function whtReceivableBackfill(): Migration
{
    $matches = glob(base_path('src/Core/Accounting/Database/Migrations/*wht_receivable*.php'));

    if ($matches === false || $matches === []) {
        test()->fail(
            'No WHT Receivable backfill migration found under '
            .'src/Core/Accounting/Database/Migrations. ADR 0017 §A requires a raw-SQL, RLS-bypass migration '
            .'that CREATES 1180 WHT Receivable for every existing company (filename must contain '
            .'"wht_receivable").'
        );
    }

    /** @var Migration $migration */
    $migration = require $matches[0];

    return $migration;
}

/**
 * Every active account carrying the WHT-receivable key in the given company, resolved by key exactly as the
 * posting map must — never by code `1180`, which a company may renumber.
 *
 * @return list<Account>
 */
function whtReceivableAccounts(string $companyId): array
{
    return Account::query()
        ->forCompany($companyId)
        ->where('system_key', Account::WHT_RECEIVABLE)
        ->orderBy('code')
        ->get()
        ->all();
}

/**
 * Removes a company's WHT Receivable account entirely, simulating a company provisioned before the account ever
 * existed. Like Customer Advances — and unlike Trade Receivables, which was only re-keyed — the legacy state is
 * the account's complete absence, because it shipped in no earlier template.
 */
function makeLegacyWithoutWht(string $companyId): void
{
    DB::table('accounts')
        ->where('company_id', $companyId)
        ->where('system_key', Account::WHT_RECEIVABLE)
        ->delete();
}

/**
 * Turns the template-provisioned `1180` into a plain, hand-made-looking account by stripping its system key,
 * leaving code `1180` taken by a NON-system account. A create-not-stamp backfill must leave this account alone
 * and create the keyed WHT account under a varied code — never re-stamp the pre-existing `1180`.
 */
function makeHandMade1180(string $companyId): void
{
    DB::table('accounts')
        ->where('company_id', $companyId)
        ->where('system_key', Account::WHT_RECEIVABLE)
        ->update(['system_key' => null, 'is_system' => false]);
}

describe('the system key', function (): void {
    it('is a fifth key with the value ADR 0017 fixes', function (): void {
        // The whole feature resolves the account by this key, never by code 1180 — a company may renumber.
        expect(Account::WHT_RECEIVABLE)->toBe('wht_receivable');
    });
});

describe('the chart template', function (): void {
    it('advances its version for the added account', function (): void {
        // ChartTemplate::VERSION's own contract: bump on any addition. Gate-2 APPROVED the target value.
        expect(ChartTemplate::VERSION)->toBe('2026.08-lk-sme-4');
    });

    it('lists 1180 WHT Receivable as a required system account', function (): void {
        $required = collect(ChartTemplate::requiredSystemAccounts())
            ->firstWhere('system', Account::WHT_RECEIVABLE);

        expect($required)->not->toBeNull()
            ->and($required['code'])->toBe('1180')
            ->and($required['name'])->toBe('WHT Receivable')
            ->and($required['type'])->toBe(AccountType::Asset)
            ->and($required['postable'])->toBeTrue();
    });
});

describe('a newly provisioned company', function (): void {
    it('receives exactly one WHT Receivable system account, correctly classified', function (): void {
        $accounts = whtReceivableAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1);

        $wht = $accounts[0];

        expect($wht->system_key)->toBe(Account::WHT_RECEIVABLE)
            ->and($wht->system_key)->toBe('wht_receivable')
            ->and($wht->is_system)->toBeTrue()
            // Postable, or the WHT debit has nowhere to land — a heading takes no journal lines.
            ->and($wht->is_postable)->toBeTrue()
            ->and($wht->is_active)->toBeTrue()
            ->and($wht->type)->toBe(AccountType::Asset)
            // Derived from the type on save; the debit side of a WHT receipt depends on it being an asset.
            ->and($wht->normal_balance)->toBe(NormalBalance::Debit)
            ->and($wht->code)->toBe('1180')
            ->and($wht->name)->toBe('WHT Receivable')
            ->and($wht->company_id)->toBe($this->company->getKey())
            ->and($wht->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($wht->template_version)->toBe(ChartTemplate::VERSION);
    });

    it('files 1180 under Current Assets (1100), directly after 1170 and renumbering nothing', function (): void {
        $wht = whtReceivableAccounts($this->company->getKey())[0];
        $parent = Account::query()->whereKey($wht->parent_id)->first();

        expect($parent)->not->toBeNull()
            ->and($parent->code)->toBe('1100')
            ->and($parent->name)->toBe('Current Assets')
            // The pre-existing 1170 leaf — its nearest sibling in kind — is untouched: 1180 is a free code, not
            // a renumber (the same discipline that placed 2180 Customer Advances as the first free liability).
            ->and(Account::query()->forCompany($this->company->getKey())->where('code', '1170')->value('name'))
            ->toBe('Input VAT Recoverable');
    });

    it('resolves the account by key, the way every WHT posting will', function (): void {
        $resolved = Account::query()
            ->forCompany($this->company->getKey())
            ->withSystemKey(Account::WHT_RECEIVABLE)
            ->first();

        expect($resolved)->not->toBeNull()
            ->and($resolved->code)->toBe('1180')
            ->and($resolved->acceptsPostings())->toBeTrue();
    });

    it('is given no second one by repeated provisioning', function (): void {
        $before = Account::query()->forCompany($this->company->getKey())->count();

        expect($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and($this->template->ensureSystemAccounts($this->company))->toBe([])
            ->and(whtReceivableAccounts($this->company->getKey()))->toHaveCount(1)
            ->and(Account::query()->forCompany($this->company->getKey())->count())->toBe($before);
    });

    it('receives one even when it declined the starter chart', function (): void {
        // A company with no chart at all must still be able to receive withholding, which is why the key is in
        // requiredSystemAccounts() and not only in the template.
        $bare = $this->createWorkspace('bare')['company'];

        $this->template->ensureSystemAccounts($bare);

        $accounts = whtReceivableAccounts($bare->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Asset)
            ->and($accounts[0]->company_id)->toBe($bare->getKey());
    });
});

describe('the backfill migration', function (): void {
    it('creates the account for a company that predates it', function (): void {
        makeLegacyWithoutWht($this->company->getKey());

        expect(whtReceivableAccounts($this->company->getKey()))->toHaveCount(0);

        whtReceivableBackfill()->up();

        $accounts = whtReceivableAccounts($this->company->getKey());

        expect($accounts)->toHaveCount(1)
            ->and($accounts[0]->is_system)->toBeTrue()
            ->and($accounts[0]->is_postable)->toBeTrue()
            ->and($accounts[0]->is_active)->toBeTrue()
            ->and($accounts[0]->type)->toBe(AccountType::Asset)
            ->and($accounts[0]->normal_balance)->toBe(NormalBalance::Debit)
            // is_system and system_key must be written together, or accounts_system_key_check refuses the row —
            // the exact trap the Customer Advances backfill documents.
            ->and($accounts[0]->system_key)->toBe('wht_receivable')
            // A statement about the past, not a template application — so no template version is recorded.
            ->and($accounts[0]->template_version)->toBeNull();
    });

    it('leaves every company holding exactly one afterwards, the "nothing left behind" guarantee', function (): void {
        makeLegacyWithoutWht($this->company->getKey());

        whtReceivableBackfill()->up();

        // A company whose first WHT receipt would otherwise fail months later is caught now.
        expect(whtReceivableAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('is safe to run more than once', function (): void {
        makeLegacyWithoutWht($this->company->getKey());

        $migration = whtReceivableBackfill();
        $migration->up();
        $migration->up();
        whtReceivableBackfill()->up();

        expect(whtReceivableAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('reaches every tenant, which is why it suspends row level security', function (): void {
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        $this->template->apply($other['company']);
        makeLegacyWithoutWht($other['company']->getKey());

        $this->withinTenant($this->acme['tenant']);
        makeLegacyWithoutWht($this->company->getKey());

        whtReceivableBackfill()->up();

        // A migration has no tenant, so without the bypass this would touch nothing on a FORCED table and
        // report success. Both tenants provisioned is the proof it did not.
        $provisioned = RowLevelSecurity::bypass(fn (): array => DB::table('accounts')
            ->whereIn('company_id', [$this->company->getKey(), $other['company']->getKey()])
            ->where('system_key', 'wht_receivable')
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
        // The company still has its template-provisioned 1180. A create-not-stamp migration must skip it, or
        // accounts_company_system_key_unique would refuse the duplicate.
        whtReceivableBackfill()->up();

        expect(whtReceivableAccounts($this->company->getKey()))->toHaveCount(1);
    });

    it('leaves a hand-made 1180 alone, creating the keyed account under a varied code', function (): void {
        // Create-not-stamp, made sharp: a legacy company that renumbered and already uses code 1180 for a plain
        // (non-system) account must NOT have that account re-stamped as the WHT system account. The backfill
        // resolves by key, finds none, and creates 1180 → the first free variation (1180-1), exactly as the
        // Customer Advances backfill's availableCode() does. The hand-made 1180 is untouched.
        makeHandMade1180($this->company->getKey());

        expect(whtReceivableAccounts($this->company->getKey()))->toHaveCount(0);

        whtReceivableBackfill()->up();

        $keyed = whtReceivableAccounts($this->company->getKey());

        expect($keyed)->toHaveCount(1)
            // The code was taken, so the keyed account varies off 1180 rather than colliding.
            ->and($keyed[0]->code)->not->toBe('1180')
            ->and($keyed[0]->code)->toContain('1180')
            ->and($keyed[0]->system_key)->toBe('wht_receivable');

        // The pre-existing hand-made 1180 was left exactly as it was: still non-system, never stamped.
        $handMade = Account::query()->forCompany($this->company->getKey())->where('code', '1180')->first();

        expect($handMade)->not->toBeNull()
            ->and($handMade->system_key)->toBeNull()
            ->and($handMade->is_system)->toBeFalse();
    });
});
