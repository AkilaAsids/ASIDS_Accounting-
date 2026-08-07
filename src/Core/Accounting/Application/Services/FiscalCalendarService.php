<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Exceptions\NoFiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates and resolves a company's fiscal calendar.
 *
 * The calendar is *derived*, never configured separately: the company already states when its year
 * begins, and a second source of truth for the same fact is a guarantee that the two will disagree
 * for some customer, on some year, in a way nobody notices until a report covers the wrong months.
 *
 * A year is generated whole — all twelve periods in one transaction — rather than a period at a
 * time. That is what makes the contiguity rule hold by construction: there is no sequence of calls
 * that produces a year with a gap in it, because there is no call that adds a single period.
 */
final readonly class FiscalCalendarService
{
    /**
     * Create a company's fiscal year, with its periods, for the year containing a given date.
     *
     * Idempotent by refusal rather than by silence: asking twice is a programming error or a race,
     * and returning the existing year as though it had just been created would hide both.
     */
    public function openYearContaining(Company $company, CarbonImmutable $date): FiscalYear
    {
        $start = $company->fiscalYearStartFor($date);
        $end = $company->fiscalYearEndFor($date);

        $existing = FiscalYear::query()
            ->forCompany($company->getKey())
            ->whereDate('starts_on', $start->toDateString())
            ->first();

        if ($existing !== null) {
            // `ResourceConflict` directly rather than a subclass: the class is final by design, and
            // models its variants as named factories rather than a hierarchy. A uniqueness collision
            // is exactly what it is reserved for.
            throw new ResourceConflict(
                sprintf('A fiscal year starting on %s already exists for this company.', $start->toDateString()),
                'fiscal-year-exists',
                ['starts_on' => $start->toDateString(), 'company' => $company->name],
            );
        }

        return DB::transaction(function () use ($company, $start, $end): FiscalYear {
            $year = new FiscalYear;

            $year->company_id = $company->getKey();
            $year->label = $this->labelFor($start, $end);
            $year->starts_on = $start;
            $year->ends_on = $end;
            $year->save();

            $this->generatePeriods($year);

            // Reloaded so the caller sees the periods that were just written rather than an empty
            // relation, which reads as "the year has no periods" at every call site.
            return $year->load('periods');
        });
    }

    /**
     * The period a date falls into, or a failure.
     *
     * Used by every posting. It refuses rather than returning null because a caller that cannot
     * find a period cannot proceed — an entry with no period has nowhere to be reported, and the
     * useful answer to the customer is "open the fiscal year first", not "something went wrong".
     */
    public function periodFor(Company $company, CarbonImmutable $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->forCompany($company->getKey())
            ->containing($date)
            ->first();

        if ($period === null) {
            throw NoFiscalPeriod::forDate($date->toDateString(), $company->name);
        }

        return $period;
    }

    /**
     * Whether a date has a period at all, without throwing. For validation, where the answer "no"
     * is a message on a field rather than a failed request.
     */
    public function hasPeriodFor(Company $company, CarbonImmutable $date): bool
    {
        return FiscalPeriod::query()
            ->forCompany($company->getKey())
            ->containing($date)
            ->exists();
    }

    /**
     * The twelve months of a year, in the company's own ordinal.
     *
     * Months rather than a configurable division, deliberately. Every statutory filing calendar this
     * platform will meet — Sri Lankan VAT, PAYE, EPF/ETF — is monthly or a multiple of months, and a
     * 4-4-5 retail calendar is a feature that should arrive with a customer asking for it rather
     * than as speculative flexibility nothing exercises.
     */
    private function generatePeriods(FiscalYear $year): void
    {
        $cursor = $year->starts_on;
        $sequence = 1;

        while ($cursor->lessThanOrEqualTo($year->ends_on)) {
            // The period ends the day before the same day-of-month next month, so a year starting on
            // the 1st gets calendar months and one starting mid-month gets aligned partial months.
            // Capped at the year's end so the final period never runs past it.
            $periodEnd = $cursor->addMonth()->subDay();

            if ($periodEnd->greaterThan($year->ends_on)) {
                $periodEnd = $year->ends_on;
            }

            $period = new FiscalPeriod;

            $period->company_id = $year->company_id;
            $period->fiscal_year_id = $year->getKey();
            $period->sequence = $sequence;
            $period->label = $cursor->format('F Y');
            $period->starts_on = $cursor;
            $period->ends_on = $periodEnd;
            $period->status = PeriodStatus::Open;
            $period->save();

            $cursor = $periodEnd->addDay();
            $sequence++;
        }
    }

    /**
     * "2026/27" when the year spans two calendar years, "2026" when it does not.
     *
     * The form a Sri Lankan accountant writes on a filing. Deriving it at each call site produced
     * three different strings on three different reports, which is why it is stored on the row.
     */
    private function labelFor(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->year === $end->year) {
            return (string) $start->year;
        }

        return $start->year.'/'.substr((string) $end->year, -2);
    }
}
