<?php

declare(strict_types=1);

use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Carbon\CarbonImmutable;

/**
 * Company creation and lifecycle.
 *
 * The properties that matter here are the ones a later phase cannot recover from. A company whose
 * base currency changed after its first invoice has a set of historical balances that silently mean
 * something different than when they were recorded; a workspace with two default companies has a
 * switcher that cannot decide where to land. Both are enforced, and both are enforced here.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);
    $this->owner = $this->acme['owner'];

    $this->service = app(CompanyService::class);
});

describe('creation', function (): void {
    it('creates the company, its primary branch and the creator’s membership as one unit', function (): void {
        $company = $this->service->create(new CreateCompanyData(name: 'Second Ledger'), $this->owner);

        // All three or none. A company with no branch cannot post a document, and a creator with
        // no membership cannot open the company they just made.
        expect($company->exists)->toBeTrue()
            ->and(Branch::query()->where('company_id', $company->getKey())->where('is_primary', true)->count())
            ->toBe(1)
            ->and(CompanyMembership::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $this->owner->getKey())
                ->whereNull('revoked_at')
                ->exists())
            ->toBeTrue();
    });

    it('derives a code and slug from the name when none is given', function (): void {
        $company = $this->service->create(new CreateCompanyData(name: 'Ceylon Tea Exports'), $this->owner);

        expect($company->code)->not->toBeEmpty()
            ->and($company->code)->toBe(mb_strtoupper($company->code))
            ->and($company->slug)->not->toBeEmpty();
    });

    it('derives a distinct identifier when the obvious one is taken', function (): void {
        $first = $this->service->create(new CreateCompanyData(name: 'Ceylon Tea Exports'), $this->owner);
        $second = $this->service->create(new CreateCompanyData(name: 'Ceylon Tea Exports'), $this->owner);

        // Codes appear on document numbers, so a collision would produce two companies issuing
        // INV-CTE-0001. The uniqueness is per workspace and case-insensitive.
        expect($second->code)->not->toBe($first->code)
            ->and($second->slug)->not->toBe($first->slug);
    });

    it('stamps the active workspace onto the company without being told', function (): void {
        $company = $this->service->create(new CreateCompanyData(name: 'Scoped Co'), $this->owner);

        expect($company->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('records the creator', function (): void {
        $company = $this->service->create(new CreateCompanyData(name: 'Attributed Co'), $this->owner);

        expect($company->created_by_id)->toBe($this->owner->getKey());
    });

    it('refuses an SVAT number without a VAT number', function (): void {
        $exception = catchPlatformException(fn () => $this->service->create(
            new CreateCompanyData(name: 'Zone Co', svatRegistrationNumber: 'SVAT-1'),
            $this->owner,
        ));

        // The database has the same constraint. Reaching it would surface as an opaque SQL error
        // instead of a message naming the actual problem.
        expect($exception->problemCode())->toBe('svat-requires-vat');
    });

    it('refuses to exceed the workspace’s company limit', function (): void {
        // One company already exists from provisioning, so a limit of 1 is already reached.
        $this->acme['tenant']->forceFill(['max_companies' => 1])->save();

        $exception = catchPlatformException(
            fn () => $this->service->create(new CreateCompanyData(name: 'Over Limit'), $this->owner),
        );

        expect($exception->problemCode())->toBe('company-limit-reached');
    });

    it('counts only active companies against the limit', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'To Be Archived'), $this->owner);
        $this->service->archive($second, $this->owner);

        $this->acme['tenant']->forceFill(['max_companies' => 2])->save();

        // An archived company occupies no seat: it can never accrue new activity, so charging for
        // it would mean a customer paying to keep their own history readable.
        expect($this->service->create(new CreateCompanyData(name: 'Within Limit'), $this->owner)->exists)
            ->toBeTrue();
    });
});

describe('accounting configuration immutability', function (): void {
    it('permits changing the base currency while the books are empty', function (): void {
        $company = $this->acme['company'];

        $updated = $this->service->update($company, ['base_currency_code' => 'USD'], $this->owner);

        // Correctable until it matters. Forcing a customer to delete and recreate a company to fix
        // a typo made during sign-up is the kind of rigidity that produces duplicate workspaces.
        expect($updated->base_currency_code)->toBe('USD');
    });

    it('permits changing the fiscal calendar while the books are empty', function (): void {
        $updated = $this->service->update(
            $this->acme['company'],
            ['fiscal_year_start_month' => 4, 'fiscal_year_start_day' => 1],
            $this->owner,
        );

        expect($updated->fiscal_year_start_month)->toBe(4);
    });

    it('refuses to change the base currency once the ledger has activity', function (): void {
        withLedgerActivity();

        $exception = catchPlatformException(fn () => $this->service->update(
            $this->acme['company'],
            ['base_currency_code' => 'USD'],
            $this->owner,
        ));

        // Every stored amount is denominated in this currency. Changing it does not convert
        // anything — it reinterprets seven years of history.
        expect($exception->problemCode())->toBe('base-currency-locked');
    });

    it('refuses to change the fiscal calendar once the ledger has activity', function (): void {
        withLedgerActivity();

        $exception = catchPlatformException(fn () => $this->service->update(
            $this->acme['company'],
            ['fiscal_year_start_month' => 7],
            $this->owner,
        ));

        expect($exception->problemCode())->toBe('fiscal-calendar-locked');
    });

    it('still permits unrelated edits once the ledger has activity', function (): void {
        withLedgerActivity();

        // The lock is on the two attributes that reinterpret history, not on the record. An
        // address correction must not require an empty ledger.
        $updated = $this->service->update(
            $this->acme['company'],
            ['city' => 'Kandy', 'phone' => '+94112345678'],
            $this->owner,
        );

        expect($updated->city)->toBe('Kandy');
    });

    it('permits re-submitting the same accounting values once the ledger has activity', function (): void {
        withLedgerActivity();

        $company = $this->acme['company'];

        // A form that posts every field back must not fail merely for including unchanged ones —
        // otherwise the lock makes the whole edit screen unusable rather than protecting two fields.
        $updated = $this->service->update($company, [
            'base_currency_code' => $company->base_currency_code,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'fiscal_year_start_day' => $company->fiscal_year_start_day,
            'city' => 'Galle',
        ], $this->owner);

        expect($updated->city)->toBe('Galle');
    });
});

describe('registrations', function (): void {
    it('keeps the VAT registered flag consistent with the number', function (): void {
        $company = $this->service->update(
            $this->acme['company'],
            ['vat_registration_number' => '104123456-7000'],
            $this->owner,
        );

        // The flag is derived, never accepted from the client: a caller that could set
        // `is_vat_registered` without a number would produce returns that cannot be filed.
        expect($company->is_vat_registered)->toBeTrue();
    });

    it('clears SVAT when VAT is cleared', function (): void {
        $company = $this->service->update($this->acme['company'], [
            'vat_registration_number' => '104123456-7000',
            'svat_registration_number' => 'SVAT-9',
        ], $this->owner);

        expect($company->is_svat_registered)->toBeTrue();

        $cleared = $this->service->update($company, ['vat_registration_number' => null], $this->owner);

        // The table's check constraint asserts SVAT implies VAT. Without this, the save fails at
        // the database with a constraint name for a message.
        expect($cleared->is_vat_registered)->toBeFalse()
            ->and($cleared->is_svat_registered)->toBeFalse()
            ->and($cleared->svat_registration_number)->toBeNull();
    });
});

describe('archiving', function (): void {
    it('refuses to archive the workspace default', function (): void {
        $exception = catchPlatformException(
            fn () => $this->service->archive($this->acme['company'], $this->owner),
        );

        expect($exception->problemCode())->toBe('cannot-archive-default-company');
    });

    it('never lets a workspace reach zero active companies', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'Second'), $this->owner);
        $this->service->makeDefault($second);

        // Refreshed because `makeDefault` demotes the incumbent with a mass update, which cannot
        // reach an already-hydrated instance. Over HTTP the model arrives from route binding and is
        // always fresh; in a test holding a reference from `beforeEach`, it is not.
        $original = $this->acme['company']->refresh();

        // The original is no longer the default, so it archives.
        $this->service->archive($original, $this->owner);

        $exception = catchPlatformException(fn () => $this->service->archive($second, $this->owner));

        // Refused — but by the *default-company* rule, not the last-company one.
        //
        // Worth stating precisely, because it means `CannotArchive::lastActiveCompany` is currently
        // unreachable: `create()` makes the first company of a workspace the default whether or not
        // the caller asked, `makeDefault()` keeps exactly one, and `archive()` refuses the default.
        // So the only company that could ever be the sole active one is always the default, and the
        // check above it fires first. The guard is a cheap backstop for a future delete path rather
        // than live behaviour, and asserting the code it actually returns is what keeps this test
        // honest about which rule is doing the work.
        expect($exception->problemCode())->toBe('cannot-archive-default-company')
            ->and(Company::query()->active()->count())->toBe(1);
    });

    it('revokes every membership so the company leaves each user’s switcher', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'Closing Down'), $this->owner);

        $this->service->archive($second, $this->owner);

        expect(CompanyMembership::query()
            ->where('company_id', $second->getKey())
            ->whereNull('revoked_at')
            ->count())->toBe(0);
    });

    it('archives rather than deletes, so history stays resolvable', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'Closed Co'), $this->owner);

        $archived = $this->service->archive($second, $this->owner);

        // A company that has appeared on a financial statement must remain readable for as long
        // as the records are retained.
        expect($archived->status)->toBe(OrganizationStatus::Archived)
            ->and($archived->archived_at)->not->toBeNull()
            ->and(Company::query()->whereKey($second->getKey())->exists())->toBeTrue();
    });

    it('refuses to make an archived company the default', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'Archived Co'), $this->owner);
        $this->service->archive($second, $this->owner);

        $exception = catchPlatformException(fn () => $this->service->makeDefault($second));

        expect($exception->problemCode())->toBe('archived-company-cannot-be-default');
    });
});

describe('the workspace default', function (): void {
    it('moves the default to another company without ever leaving two', function (): void {
        $second = $this->service->create(new CreateCompanyData(name: 'New Default'), $this->owner);

        $this->service->makeDefault($second);

        // A partial application would trip the partial unique index — after committing the first
        // write. The count is the assertion that matters, not the flag on either row.
        expect(Company::query()->where('is_default', true)->count())->toBe(1)
            ->and(Company::query()->where('is_default', true)->first()?->getKey())->toBe($second->getKey());
    });

    it('leaves exactly one default after provisioning', function (): void {
        expect(Company::query()->where('is_default', true)->count())->toBe(1);
    });
});

describe('fiscal calendar arithmetic', function (): void {
    it('resolves a calendar fiscal year', function (): void {
        $company = $this->service->update(
            $this->acme['company'],
            ['fiscal_year_start_month' => 1, 'fiscal_year_start_day' => 1],
            $this->owner,
        );

        $reference = CarbonImmutable::parse('2026-08-06');

        expect($company->usesCalendarFiscalYear())->toBeTrue()
            ->and($company->fiscalYearStartFor($reference)->toDateString())->toBe('2026-01-01')
            ->and($company->fiscalYearEndFor($reference)->toDateString())->toBe('2026-12-31');
    });

    it('resolves an April-start fiscal year, which is the Sri Lankan default', function (): void {
        $company = $this->service->update(
            $this->acme['company'],
            ['fiscal_year_start_month' => 4, 'fiscal_year_start_day' => 1],
            $this->owner,
        );

        // A date before the start month belongs to the year that began in the *previous* calendar
        // year. Getting this wrong files a return against the wrong period.
        expect($company->fiscalYearStartFor(CarbonImmutable::parse('2026-08-06'))->toDateString())
            ->toBe('2026-04-01')
            ->and($company->fiscalYearEndFor(CarbonImmutable::parse('2026-08-06'))->toDateString())
            ->toBe('2027-03-31')
            ->and($company->fiscalYearStartFor(CarbonImmutable::parse('2026-02-15'))->toDateString())
            ->toBe('2025-04-01');
    });
});

/**
 * Reports the company as having ledger activity for the rest of the test.
 *
 * The probe is a seam: the accounting tables it will really consult do not exist until a later
 * phase, and `NoLedgerActivity` is the accurate answer until they do. Swapping the binding is how
 * the locking rules can be tested now rather than being taken on trust until Accounting lands.
 */
function withLedgerActivity(): void
{
    app()->instance(
        LedgerActivityProbe::class,
        new class implements LedgerActivityProbe
        {
            public function companyHasActivity(Company $company): bool
            {
                return true;
            }

            public function branchHasActivity(Branch $branch): bool
            {
                return true;
            }

            public function earliestActivityDate(Company $company): ?DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-15');
            }
        },
    );

    // Rebound after the service was already resolved in `beforeEach`, so the instance holding the
    // old probe has to go too.
    app()->forgetInstance(CompanyService::class);
    test()->service = app(CompanyService::class);
}
