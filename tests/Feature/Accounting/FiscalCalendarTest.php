<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fiscal calendar.
 *
 * Two properties carry everything downstream, and both are about coverage rather than about any one
 * date being right:
 *
 *   * **No gaps.** A date inside no period cannot be posted at all. The customer experiences that as
 *     "the system refuses the 31st", and it is invisible until someone tries.
 *   * **No overlaps.** A date inside two periods belongs to two closing states at once, and every
 *     report that groups by period counts it twice.
 *
 * The April-start year matters more than the January one here: Sri Lanka's statutory assessment year
 * begins in April, so it is the default for this platform's first market, and it is the case where
 * naive month arithmetic goes wrong.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->calendar = app(FiscalCalendarService::class);
    $this->companies = app(CompanyService::class);
});

/**
 * A company whose fiscal year starts on a given month and day.
 */
function companyStarting(int $month, int $day): Company
{
    return test()->companies->update(
        test()->acme['company'],
        ['fiscal_year_start_month' => $month, 'fiscal_year_start_day' => $day],
        test()->owner,
    );
}

describe('generating a year', function (): void {
    it('creates a calendar year with twelve months', function (): void {
        $company = companyStarting(1, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect($year->starts_on->toDateString())->toBe('2026-01-01')
            ->and($year->ends_on->toDateString())->toBe('2026-12-31')
            ->and($year->periods)->toHaveCount(12);
    });

    it('creates an April-start year spanning two calendar years', function (): void {
        $company = companyStarting(4, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // The Sri Lankan assessment year. A date in June 2026 belongs to the year that began in
        // April 2026 and ends in March 2027 — not to a 2026 calendar year.
        expect($year->starts_on->toDateString())->toBe('2026-04-01')
            ->and($year->ends_on->toDateString())->toBe('2027-03-31')
            ->and($year->periods)->toHaveCount(12);
    });

    it('places a date before the fiscal start into the year that began the previous calendar year', function (): void {
        $company = companyStarting(4, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-02-15'));

        // February 2026 is in the year that started April 2025. Getting this wrong files a return
        // against the wrong assessment year, which is the most consequential date error available.
        expect($year->starts_on->toDateString())->toBe('2025-04-01')
            ->and($year->ends_on->toDateString())->toBe('2026-03-31');
    });

    it('labels a year spanning two calendar years the way an accountant writes it', function (): void {
        $company = companyStarting(4, 1);

        expect($this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'))->label)
            ->toBe('2026/27');
    });

    it('labels a calendar year with a single number', function (): void {
        $company = companyStarting(1, 1);

        expect($this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'))->label)
            ->toBe('2026');
    });

    it('numbers periods in the company’s own ordinal, not the calendar’s', function (): void {
        $company = companyStarting(4, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        $first = $year->periods->firstWhere('sequence', 1);

        // Period 1 is April, not January. The sequence is the accountant's ordinal.
        expect($first?->starts_on->toDateString())->toBe('2026-04-01')
            ->and($first?->label)->toBe('April 2026');
    });

    it('opens every period', function (): void {
        $company = companyStarting(4, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect($year->periods->pluck('status')->unique()->all())->toBe([PeriodStatus::Open]);
    });
});

describe('coverage', function (): void {
    it('covers every day of the year with no gap and no overlap', function (): void {
        $company = companyStarting(4, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // Walked day by day rather than checked at the boundaries. A gap of one day at a month
        // boundary is exactly the bug this guards against, and a boundary-only test steps over it.
        $cursor = $year->starts_on;
        $seen = 0;

        while ($cursor->lessThanOrEqualTo($year->ends_on)) {
            $matches = $year->periods->filter(fn (FiscalPeriod $period): bool => $period->contains($cursor));

            expect($matches)->toHaveCount(1, "No single period contains {$cursor->toDateString()}");

            $cursor = $cursor->addDay();
            $seen++;
        }

        expect($seen)->toBe(365);
    });

    it('covers a leap year’s extra day', function (): void {
        $company = companyStarting(1, 1);

        // 2028 is a leap year. February's period has to end on the 29th, and a period generated as
        // "start plus one month minus a day" gets this right only if it uses real date arithmetic.
        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2028-06-15'));

        $february = $year->periods->firstWhere('sequence', 2);

        expect($february?->ends_on->toDateString())->toBe('2028-02-29')
            ->and($year->periods->sum(fn (FiscalPeriod $p): int => $p->range()->days()))->toBe(366);
    });

    it('covers a February that ends an April-start leap year', function (): void {
        $company = companyStarting(4, 1);

        // The April 2027 year contains February 2028 — the leap day falls in the eleventh period of
        // a year that started in a non-leap calendar year.
        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2027-06-15'));

        $february = $year->periods->firstWhere('sequence', 11);

        expect($february?->starts_on->toDateString())->toBe('2028-02-01')
            ->and($february?->ends_on->toDateString())->toBe('2028-02-29');
    });

    it('handles a fiscal year starting mid-month', function (): void {
        $company = companyStarting(4, 15);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // Aligned partial months rather than calendar months. Unusual but legal, and the coverage
        // rule must still hold exactly.
        expect($year->starts_on->toDateString())->toBe('2026-04-15')
            ->and($year->ends_on->toDateString())->toBe('2027-04-14')
            ->and($year->periods->first()?->ends_on->toDateString())->toBe('2026-05-14')
            ->and($year->periods->sum(fn (FiscalPeriod $p): int => $p->range()->days()))
            ->toBe($year->range()->days());
    });

    it('never generates a period that runs past the year', function (): void {
        $company = companyStarting(4, 15);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect($year->periods->last()?->ends_on->toDateString())->toBe($year->ends_on->toDateString());
    });
});

describe('database invariants', function (): void {
    it('refuses two overlapping years for the same company at the database', function (): void {
        $company = companyStarting(1, 1);

        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // Inserted raw, bypassing the service entirely. The exclusion constraint is the control that
        // matters — a service check alone can be bypassed by a console command or a future module.
        expect(fn () => DB::table('fiscal_years')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $company->getKey(),
            'label' => 'overlapping',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses two overlapping periods for the same company at the database', function (): void {
        $company = companyStarting(1, 1);

        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect(fn () => DB::table('fiscal_periods')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $company->getKey(),
            'fiscal_year_id' => $year->getKey(),
            'sequence' => 99,
            'label' => 'overlapping',
            'starts_on' => '2026-03-15',
            'ends_on' => '2026-04-15',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('permits two companies to have identical years', function (): void {
        $company = companyStarting(1, 1);
        $second = $this->companies->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->owner,
        );

        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // The exclusion constraint is scoped per company. Two legal entities in one workspace keep
        // separate books over the same calendar.
        expect($this->calendar->openYearContaining($second, CarbonImmutable::parse('2026-06-15'))->exists)
            ->toBeTrue();
    });

    it('refuses a period whose status and closed timestamp disagree', function (): void {
        $company = companyStarting(1, 1);
        $year = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // A period marked closed with no timestamp cannot be reported on; one with a timestamp but
        // still open still accepts postings. Both columns move together or neither does.
        expect(fn () => DB::table('fiscal_periods')
            ->where('id', $year->periods->first()?->getKey())
            ->update(['status' => 'closed']))
            ->toThrow(QueryException::class);
    });
});

describe('resolving a date to a period', function (): void {
    it('finds the period a date falls into', function (): void {
        $company = companyStarting(4, 1);
        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        $period = $this->calendar->periodFor($company, CarbonImmutable::parse('2026-06-15'));

        expect($period->label)->toBe('June 2026');
    });

    it('finds the period on its first and last day', function (): void {
        $company = companyStarting(4, 1);
        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        // The inclusive-boundary rule, asserted where it actually bites. A half-open range silently
        // drops the last day's trading into the following month.
        expect($this->calendar->periodFor($company, CarbonImmutable::parse('2026-06-01'))->label)->toBe('June 2026')
            ->and($this->calendar->periodFor($company, CarbonImmutable::parse('2026-06-30'))->label)->toBe('June 2026');
    });

    it('refuses a date with no period, naming the fix', function (): void {
        $company = companyStarting(4, 1);
        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        $exception = catchPlatformException(
            fn () => $this->calendar->periodFor($company, CarbonImmutable::parse('2030-06-15')),
        );

        // "Open the fiscal year first" is something the customer can act on. A generic failure is not.
        expect($exception->problemCode())->toBe('no-fiscal-period');
    });

    it('answers without throwing when asked whether a date is covered', function (): void {
        $company = companyStarting(4, 1);
        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect($this->calendar->hasPeriodFor($company, CarbonImmutable::parse('2026-06-15')))->toBeTrue()
            ->and($this->calendar->hasPeriodFor($company, CarbonImmutable::parse('2030-06-15')))->toBeFalse();
    });

    it('does not resolve a date to another company’s period', function (): void {
        $company = companyStarting(1, 1);
        $second = $this->companies->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->owner,
        );

        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        expect($this->calendar->hasPeriodFor($second, CarbonImmutable::parse('2026-06-15')))->toBeFalse();
    });
});

describe('opening a year twice', function (): void {
    it('refuses rather than silently returning the existing year', function (): void {
        $company = companyStarting(1, 1);

        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        $exception = catchPlatformException(
            fn () => $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-09-01')),
        );

        // Asking twice is a programming error or a race. Returning the existing year would let the
        // second caller believe it had just created what it is about to write periods into.
        expect($exception->problemCode())->toBe('fiscal-year-exists');
    });

    it('leaves no partial year behind when generation fails', function (): void {
        $company = companyStarting(1, 1);

        $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

        try {
            $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-09-01'));
        } catch (Throwable) {
            // Expected.
        }

        // One year, twelve periods. A half-written second year would leave the company with periods
        // belonging to a year that does not exist.
        expect(FiscalYear::query()->forCompany($company->getKey())->count())->toBe(1)
            ->and(FiscalPeriod::query()->forCompany($company->getKey())->count())->toBe(12);
    });

    it('opens consecutive years that meet exactly', function (): void {
        $company = companyStarting(4, 1);

        $first = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));
        $second = $this->calendar->openYearContaining($company, CarbonImmutable::parse('2027-06-15'));

        // No gap between years either: 31 March to 1 April, with nothing in between.
        expect($second->starts_on->toDateString())->toBe($first->ends_on->addDay()->toDateString());
    });
});
