<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Controllers;

use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PeriodCloseService;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fiscal calendar, and closing.
 *
 * Closing and reopening are separate endpoints under different permissions. The reopen route demands a
 * reason in the body rather than accepting one optionally, because reopening changes figures that may
 * already have been reported and the trail has to say why someone decided that was correct.
 */
final class FiscalPeriodController extends ApiController
{
    public function __construct(
        private readonly FiscalCalendarService $calendar,
        private readonly PeriodCloseService $close,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', FiscalPeriod::class);

        $years = FiscalYear::query()
            ->forCompany((string) $company->getKey())
            ->with('periods')
            ->orderByDesc('starts_on')
            ->get();

        return ApiResponse::item(array_map(
            static fn (FiscalYear $year): array => [
                'id' => $year->getKey(),
                'label' => $year->label,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'is_closed' => $year->isClosed(),
                'closed_at' => $year->closed_at?->toIso8601String(),
                'closing_entry_id' => $year->closing_entry_id,
                'periods' => $year->periods->map(static fn (FiscalPeriod $period): array => [
                    'id' => $period->getKey(),
                    'sequence' => $period->sequence,
                    'label' => $period->label,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'status' => $period->status->value,
                    'status_label' => $period->status->label(),
                    'accepts_postings' => $period->acceptsPostings(),
                    'closed_at' => $period->closed_at?->toIso8601String(),
                    'reopened_at' => $period->reopened_at?->toIso8601String(),
                    'reopen_reason' => $period->reopen_reason,
                ])->values()->all(),
            ],
            $years->all(),
        ));
    }

    /**
     * Open the fiscal year containing a date, generating its periods.
     *
     * Idempotent: a year that already exists is returned rather than duplicated, so a client that
     * calls this before every posting does no harm.
     */
    public function openYear(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        if (! $this->currentUser()->can('accounting.periods.close')) {
            // Generating the calendar is an administrative act, so it shares the permission that
            // governs the calendar rather than the read-only one.
            abort(403);
        }

        $validated = $request->validate(['contains' => ['required', 'date']]);

        $year = $this->calendar->openYearContaining(
            $company,
            CarbonImmutable::parse((string) $validated['contains']),
        );

        return ApiResponse::created([
            'id' => $year->getKey(),
            'label' => $year->label,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'period_count' => $year->periods->count(),
        ]);
    }

    public function closePeriod(Company $company, FiscalPeriod $period): JsonResponse
    {
        $this->authorize('close', $period);

        $closed = $this->close->close($period, $this->currentUser());

        return ApiResponse::item([
            'id' => $closed->getKey(),
            'label' => $closed->label,
            'status' => $closed->status->value,
            'closed_at' => $closed->closed_at?->toIso8601String(),
        ]);
    }

    public function reopenPeriod(Request $request, Company $company, FiscalPeriod $period): JsonResponse
    {
        $this->authorize('reopen', $period);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $reopened = $this->close->reopen($period, (string) $validated['reason'], $this->currentUser());

        return ApiResponse::item([
            'id' => $reopened->getKey(),
            'label' => $reopened->label,
            'status' => $reopened->status->value,
            'reopened_at' => $reopened->reopened_at?->toIso8601String(),
            'reopen_reason' => $reopened->reopen_reason,
        ]);
    }

    /**
     * The year's result without closing it, so an accountant sees the figure before committing.
     */
    public function yearResult(Company $company, FiscalYear $year): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', FiscalPeriod::class);

        return ApiResponse::item([
            'fiscal_year_id' => $year->getKey(),
            'label' => $year->label,
            'is_closed' => $year->isClosed(),
            'net_result' => $this->close->netResultFor($year)->toDecimalString(),
            'currency' => $company->base_currency_code,
        ]);
    }

    public function closeYear(Company $company, FiscalYear $year): JsonResponse
    {
        $this->authorize('view', $company);

        if (! $this->currentUser()->can('accounting.periods.close-year')) {
            abort(403);
        }

        $entry = $this->close->closeYear($year, $this->currentUser());

        return ApiResponse::item([
            'fiscal_year_id' => $year->getKey(),
            'closed' => true,
            // Null for a year with no trading. Stated rather than omitted so a client does not read
            // its absence as a failure.
            'closing_entry_id' => $entry?->getKey(),
            'closing_entry_number' => $entry?->number,
        ]);
    }
}
