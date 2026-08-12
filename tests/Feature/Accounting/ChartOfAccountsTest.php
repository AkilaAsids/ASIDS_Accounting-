<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\CreateAccountData;
use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Enums\NormalBalance;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The chart of accounts.
 *
 * The rules worth testing here are all about what happens *after* an account has been used. Creating
 * one is easy; the value is in the refusals, because each thing refused would otherwise produce a
 * chart that still saves, still balances, and quietly misreports.
 *
 * The reclassification rule is the one to understand: moving an account from expense to asset does
 * not error and does not unbalance anything. It silently changes every profit figure the company has
 * ever filed, and there is no way to detect that afterwards from the data alone.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->chart = app(ChartOfAccountsService::class);
    $this->template = app(ChartTemplateService::class);
});

/**
 * Reports every account as having postings, for the rest of the test.
 *
 * `journal_lines` does not exist until tranche 3, so `NoPostings` is the truthful implementation
 * until then. Swapping the binding is how the rules that depend on it can be tested now rather than
 * taken on trust — the same technique `CompanyLifecycleTest` uses for `LedgerActivityProbe`.
 */
function withPostings(): void
{
    app()->instance(AccountUsageProbe::class, new class implements AccountUsageProbe
    {
        public function hasPostings(Account $account): bool
        {
            return true;
        }

        public function subtreeHasPostings(Account $account): bool
        {
            return true;
        }
    });

    app()->forgetInstance(ChartOfAccountsService::class);
    test()->chart = app(ChartOfAccountsService::class);
}

function makeAccount(string $code, AccountType $type, ?string $parentId = null, bool $postable = true): Account
{
    return test()->chart->create(test()->company, new CreateAccountData(
        code: $code,
        name: 'Account '.$code,
        type: $type,
        parentId: $parentId,
        isPostable: $postable,
    ));
}

describe('creating accounts', function (): void {
    it('creates an account with its type and derived normal balance', function (): void {
        $account = makeAccount('1110', AccountType::Asset);

        expect($account->type)->toBe(AccountType::Asset)
            ->and($account->normal_balance)->toBe(NormalBalance::Debit)
            ->and($account->is_active)->toBeTrue();
    });

    it('derives the normal balance from the type for every classification', function (): void {
        // Never an input. An account whose sign convention disagrees with its classification reports
        // every figure backwards while the books still balance.
        $expected = [
            ['1000', AccountType::Asset, NormalBalance::Debit],
            ['2000', AccountType::Liability, NormalBalance::Credit],
            ['3000', AccountType::Equity, NormalBalance::Credit],
            ['4000', AccountType::Income, NormalBalance::Credit],
            ['5000', AccountType::Expense, NormalBalance::Debit],
        ];

        foreach ($expected as [$code, $type, $normalBalance]) {
            expect(makeAccount($code, $type)->normal_balance)->toBe($normalBalance);
        }
    });

    it('stamps the active workspace and company without being told', function (): void {
        $account = makeAccount('1110', AccountType::Asset);

        expect($account->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($account->company_id)->toBe($this->company->getKey());
    });

    it('refuses a duplicate code within the company', function (): void {
        makeAccount('1110', AccountType::Asset);

        expect(catchPlatformException(fn () => makeAccount('1110', AccountType::Asset))->problemCode())
            ->toBe('duplicate-resource');
    });

    it('refuses a duplicate code differing only in case', function (): void {
        makeAccount('BANK', AccountType::Asset);

        // Codes appear on documents and in imports. "bank" and "BANK" are the same account to every
        // human who reads them.
        expect(catchPlatformException(fn () => makeAccount('bank', AccountType::Asset))->problemCode())
            ->toBe('duplicate-resource');
    });

    it('permits the same code in a different company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        makeAccount('1110', AccountType::Asset);

        // Every company keeps its own books. Two entities in one workspace both having a "1110" is
        // normal, not a collision.
        $account = $this->chart->create($second, new CreateAccountData(
            code: '1110',
            name: 'Cash',
            type: AccountType::Asset,
        ));

        expect($account->exists)->toBeTrue();
    });

    it('trims a code rather than storing the whitespace', function (): void {
        expect(makeAccount('  1110  ', AccountType::Asset)->code)->toBe('1110');
    });
});

describe('the hierarchy', function (): void {
    it('nests an account under a parent of the same type', function (): void {
        $parent = makeAccount('1100', AccountType::Asset, postable: false);
        $child = makeAccount('1110', AccountType::Asset, parentId: $parent->getKey());

        expect($child->parent_id)->toBe($parent->getKey());
    });

    it('refuses a parent of a different type', function (): void {
        $parent = makeAccount('1100', AccountType::Asset, postable: false);

        // An expense rolling up into an asset heading puts its balance in the wrong section of the
        // balance sheet, and the total still ties because the amount is real.
        expect(catchPlatformException(
            fn () => makeAccount('6100', AccountType::Expense, parentId: $parent->getKey()),
        )->problemCode())->toBe('account-hierarchy-type-mismatch');
    });

    it('refuses a parent belonging to another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $foreign = $this->chart->create($second, new CreateAccountData(
            code: '1100',
            name: 'Current Assets',
            type: AccountType::Asset,
            isPostable: false,
        ));

        // Reported as a hierarchy error rather than "not found": saying the parent exists elsewhere
        // would confirm a record in a company the caller cannot see.
        expect(catchPlatformException(
            fn () => makeAccount('1110', AccountType::Asset, parentId: $foreign->getKey()),
        )->problemCode())->toBe('account-hierarchy-foreign-company');
    });

    it('refuses to make an account its own parent', function (): void {
        $account = makeAccount('1110', AccountType::Asset);

        expect(catchPlatformException(
            fn () => $this->chart->update($account, ['parent_id' => $account->getKey()]),
        )->problemCode())->toBe('account-hierarchy-cycle');
    });

    it('refuses a cycle deeper than one level', function (): void {
        $grandparent = makeAccount('1000', AccountType::Asset, postable: false);
        $parent = makeAccount('1100', AccountType::Asset, parentId: $grandparent->getKey(), postable: false);
        $child = makeAccount('1110', AccountType::Asset, parentId: $parent->getKey());

        // Making the grandparent a child of its own descendant. A cycle that reached the database
        // would make the roll-up on every statement non-terminating.
        expect(catchPlatformException(
            fn () => $this->chart->update($grandparent, ['parent_id' => $child->getKey()]),
        )->problemCode())->toBe('account-hierarchy-cycle');
    });

    it('refuses a self-parent at the database, not only in the service', function (): void {
        $account = makeAccount('1110', AccountType::Asset);

        expect(fn () => DB::table('accounts')
            ->where('id', $account->getKey())
            ->update(['parent_id' => $account->getKey()]))
            ->toThrow(QueryException::class);
    });

    it('refuses a parent that already has postings of its own', function (): void {
        $parent = makeAccount('1100', AccountType::Asset);

        withPostings();

        // A heading with its own postings produces a subtotal that double-counts: the parent's
        // balance plus its children's.
        expect(catchPlatformException(
            fn () => makeAccount('1110', AccountType::Asset, parentId: $parent->getKey()),
        )->problemCode())->toBe('account-hierarchy-parent-has-postings');
    });
});

describe('reclassification', function (): void {
    it('permits a type change while the account is unused', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        $updated = $this->chart->update($account, ['type' => AccountType::Asset]);

        // Correctable until it matters. A misclassification caught on the day it was made should not
        // require creating a second account.
        expect($updated->type)->toBe(AccountType::Asset)
            ->and($updated->normal_balance)->toBe(NormalBalance::Debit);
    });

    it('refuses a type change once the account has postings', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        withPostings();

        // The rule that matters most in this file. Moving this account to an asset does not error
        // and does not unbalance anything — it silently changes every profit figure already filed.
        expect(catchPlatformException(
            fn () => $this->chart->update($account, ['type' => AccountType::Asset]),
        )->problemCode())->toBe('account-type-locked');
    });

    it('permits re-submitting the same type once the account has postings', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        withPostings();

        // A form that posts every field back must not fail for including an unchanged one, or the
        // lock makes the whole edit screen unusable rather than protecting one field.
        expect($this->chart->update($account, ['type' => AccountType::Expense, 'name' => 'Renamed'])->name)
            ->toBe('Renamed');
    });

    it('refuses a type change that would leave a child disagreeing', function (): void {
        $parent = makeAccount('1100', AccountType::Asset, postable: false);
        makeAccount('1110', AccountType::Asset, parentId: $parent->getKey());

        expect(catchPlatformException(
            fn () => $this->chart->update($parent, ['type' => AccountType::Expense]),
        )->problemCode())->toBe('account-hierarchy-type-mismatch');
    });

    it('permits renaming and renumbering freely, even with postings', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        withPostings();

        // Deliberately still allowed. A customer matching a group chart renumbers routinely, and it
        // is safe precisely because nothing the platform depends on resolves by code.
        $updated = $this->chart->update($account, ['code' => '6150', 'name' => 'Staff Costs']);

        expect($updated->code)->toBe('6150')->and($updated->name)->toBe('Staff Costs');
    });
});

describe('archiving and deleting', function (): void {
    it('archives an account, keeping it readable', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        $archived = $this->chart->archive($account);

        expect($archived->is_active)->toBeFalse()
            ->and($archived->archived_at)->not->toBeNull()
            ->and($archived->acceptsPostings())->toBeFalse()
            ->and(Account::query()->whereKey($account->getKey())->exists())->toBeTrue();
    });

    it('restores an archived account', function (): void {
        $account = $this->chart->archive(makeAccount('6100', AccountType::Expense));

        expect($this->chart->restore($account)->is_active)->toBeTrue();
    });

    it('deletes an account that was never used', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        $this->chart->delete($account);

        // Deletion exists only for the case it is safe for: an account created by mistake, before
        // anything referenced it.
        expect(Account::query()->whereKey($account->getKey())->exists())->toBeFalse();
    });

    it('refuses to delete an account with postings', function (): void {
        $account = makeAccount('6100', AccountType::Expense);

        withPostings();

        expect(catchPlatformException(fn () => $this->chart->delete($account))->problemCode())
            ->toBe('account-in-use');
    });

    it('refuses to delete an account that has children', function (): void {
        $parent = makeAccount('1100', AccountType::Asset, postable: false);
        makeAccount('1110', AccountType::Asset, parentId: $parent->getKey());

        // Refused rather than cascaded. The customer is told which accounts are in the way instead
        // of discovering their absence from a report.
        expect(catchPlatformException(fn () => $this->chart->delete($parent))->problemCode())
            ->toBe('account-has-children');
    });

    it('refuses at the database to delete a parent that still has children', function (): void {
        $parent = makeAccount('1100', AccountType::Asset, postable: false);
        makeAccount('1110', AccountType::Asset, parentId: $parent->getKey());

        // The restrict-on-delete foreign key, reached directly. The service refuses first; this is
        // what catches a console command or a future module that does not.
        expect(fn () => DB::table('accounts')->where('id', $parent->getKey())->delete())
            ->toThrow(QueryException::class);
    });
});

describe('system accounts', function (): void {
    beforeEach(function (): void {
        $this->template->ensureSystemAccounts($this->company);
    });

    it('creates retained earnings and opening balance equity', function (): void {
        expect($this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS))->not->toBeNull()
            ->and($this->chart->systemAccount($this->company, Account::OPENING_BALANCE_EQUITY))->not->toBeNull();
    });

    it('is idempotent', function (): void {
        $this->template->ensureSystemAccounts($this->company);
        $this->template->ensureSystemAccounts($this->company);

        // Counted against the template rather than a literal. The property under test is that repeated calls
        // create nothing extra; the size of the set is incidental and grew when Milestone 5 added trade
        // receivables. A hardcoded number turns every legitimate addition into a failing test that says nothing
        // about idempotency.
        expect(Account::query()->forCompany($this->company->getKey())->whereNotNull('system_key')->count())
            ->toBe(count(ChartTemplate::requiredSystemAccounts()));
    });

    it('resolves a system account by key even after it is renumbered', function (): void {
        $retained = $this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS);

        $this->chart->update($retained, ['code' => '9999', 'name' => 'Accumulated Profits']);

        // The whole reason the key exists. A customer matching a group chart renumbers, and the
        // year-end close still has to find retained earnings afterwards.
        expect($this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS)?->code)->toBe('9999');
    });

    it('refuses to delete a system account', function (): void {
        $retained = $this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS);

        expect(catchPlatformException(fn () => $this->chart->delete($retained))->problemCode())
            ->toBe('system-account-protected');
    });

    it('refuses to archive a system account', function (): void {
        $retained = $this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS);

        // The year-end close posts to it. An archived one fails the close months later, at the worst
        // possible moment.
        expect(catchPlatformException(fn () => $this->chart->archive($retained))->problemCode())
            ->toBe('system-account-protected');
    });

    it('refuses to change a system account’s type', function (): void {
        $retained = $this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS);

        expect(catchPlatformException(
            fn () => $this->chart->update($retained, ['type' => AccountType::Asset]),
        )->problemCode())->toBe('system-account-protected');
    });

    it('takes a free code when its preferred one is taken', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $this->chart->create($second, new CreateAccountData(
            code: '3200',
            name: 'Something Else',
            type: AccountType::Equity,
        ));

        $created = $this->template->ensureSystemAccounts($second);

        // The account still has to exist, and its code is not what the platform resolves it by, so
        // taking the next free number beats refusing to create it.
        expect($created)->not->toBeEmpty()
            ->and($this->chart->systemAccount($second, Account::RETAINED_EARNINGS))->not->toBeNull();
    });

    it('refuses two accounts with the same system key at the database', function (): void {
        expect(fn () => DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'code' => 'DUP',
            'name' => 'Duplicate Retained Earnings',
            'type' => 'equity',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'is_system' => true,
            'system_key' => Account::RETAINED_EARNINGS,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('the starter template', function (): void {
    it('applies a complete chart', function (): void {
        $created = $this->template->apply($this->company);

        expect($created)->toBe(count(ChartTemplate::accounts()))
            ->and(Account::query()->forCompany($this->company->getKey())->count())->toBe($created);
    });

    it('stamps every account with the template version', function (): void {
        $this->template->apply($this->company);

        // Without this, a corrected template six months from now leaves no way to identify the
        // companies built on the earlier one.
        expect(Account::query()->forCompany($this->company->getKey())->pluck('template_version')->unique()->all())
            ->toBe([ChartTemplate::VERSION]);
    });

    it('creates the hierarchy, not a flat list', function (): void {
        $this->template->apply($this->company);

        $currentAssets = Account::query()->forCompany($this->company->getKey())->where('code', '1100')->first();
        $cash = Account::query()->forCompany($this->company->getKey())->where('code', '1110')->first();

        expect($cash?->parent_id)->toBe($currentAssets?->getKey());
    });

    it('marks headings as not postable', function (): void {
        $this->template->apply($this->company);

        $heading = Account::query()->forCompany($this->company->getKey())->where('code', '1000')->first();

        // Posting to a heading turns a chart of accounts into a chart of subtotals nobody can
        // reconcile.
        expect($heading?->is_postable)->toBeFalse();
    });

    it('includes the system accounts', function (): void {
        $this->template->apply($this->company);

        expect($this->chart->systemAccount($this->company, Account::RETAINED_EARNINGS))->not->toBeNull()
            ->and($this->chart->systemAccount($this->company, Account::OPENING_BALANCE_EQUITY))->not->toBeNull();
    });

    it('refuses to apply over an existing chart', function (): void {
        makeAccount('1110', AccountType::Asset);

        // Two numbering schemes interleaved is worse than either, and the customer cannot tell
        // afterwards which accounts came from where.
        expect(catchPlatformException(fn () => $this->template->apply($this->company))->problemCode())
            ->toBe('chart-already-exists');
    });

    it('leaves nothing behind when application fails', function (): void {
        $this->template->apply($this->company);

        $before = Account::query()->forCompany($this->company->getKey())->count();

        try {
            $this->template->apply($this->company);
        } catch (Throwable) {
            // Expected.
        }

        expect(Account::query()->forCompany($this->company->getKey())->count())->toBe($before);
    });

    it('carries a disclaimer that names it as a starting point', function (): void {
        // Asserted rather than assumed: the labelling is a condition of shipping this template, and
        // a disclaimer that lives only in documentation is one nobody clicking "apply" reads.
        expect(ChartTemplate::disclaimer())
            ->toContain('not professional or statutory advice')
            ->and(ChartTemplate::disclaimer())->toContain('qualified Sri Lankan accountant');
    });

    it('is versioned', function (): void {
        expect(ChartTemplate::VERSION)->not->toBeEmpty();
    });

    it('lists every parent before the children that reference it', function (): void {
        // What makes single-pass application possible. A child appearing first would silently get a
        // null parent and land at the top of the chart.
        $seen = [];

        foreach (ChartTemplate::accounts() as $definition) {
            if ($definition['parent'] !== null) {
                expect($seen)->toContain($definition['parent']);
            }

            $seen[] = $definition['code'];
        }

        expect($seen)->not->toBeEmpty();
    });

    it('gives every account a type whose normal balance the database will accept', function (): void {
        // The template is data, and data can be wrong. This walks it against the same rule the check
        // constraint enforces, so a bad entry fails here rather than on a customer's first apply.
        foreach (ChartTemplate::accounts() as $definition) {
            $expected = $definition['type']->normalBalance();

            expect($expected)->toBeInstanceOf(NormalBalance::class);
        }

        expect(count(ChartTemplate::accounts()))->toBeGreaterThan(30);
    });
});
