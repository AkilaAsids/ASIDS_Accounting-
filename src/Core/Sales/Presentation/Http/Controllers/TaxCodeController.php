<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Controllers;

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Sales\Presentation\Http\Requests\EndTaxCodeRangeRequest;
use Asids\Core\Sales\Presentation\Http\Requests\StoreTaxCodeRequest;
use Asids\Core\Sales\Presentation\Http\Requests\UpdateTaxCodeRequest;
use Asids\Core\Sales\Presentation\Http\Resources\TaxCodeResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tax codes an invoice can charge, nested under their company.
 *
 * A bounded configuration catalogue like the chart of accounts, not a growing transactional list —
 * so the index follows `AccountController::index`, not the paginated `JournalEntryController` one:
 * unpaginated, `meta.total`, `active_only` defaulting to true.
 */
final class TaxCodeController extends ApiController
{
    public function __construct(
        private readonly TaxCodeService $taxCodes,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', TaxCode::class);

        $query = TaxCode::query()
            ->forCompany((string) $company->getKey())
            ->when($request->boolean('active_only', true), static fn ($query) => $query->active());

        if ($request->filled('code')) {
            $query->withCode($request->string('code')->toString());
        } else {
            // The order a chart of accounts is read in: by code, and — because one code may carry
            // several historical ranges — the newest range first.
            $query->orderBy('code')->orderByDesc('effective_from');
        }

        $taxCodes = $query->get();

        return ApiResponse::collection(
            collection: TaxCodeResource::collection($taxCodes),
            meta: ['total' => $taxCodes->count()],
        );
    }

    public function show(Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('view', $taxCode);

        return ApiResponse::item(new TaxCodeResource($taxCode));
    }

    public function store(StoreTaxCodeRequest $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', TaxCode::class);

        $taxCode = $this->taxCodes->create(
            $company,
            TaxCodeData::fromArray($request->validated()),
            $this->currentUser()->getKey(),
        );

        return ApiResponse::created(new TaxCodeResource($taxCode));
    }

    public function update(UpdateTaxCodeRequest $request, Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('update', $taxCode);

        return ApiResponse::item(new TaxCodeResource(
            $this->taxCodes->update($taxCode, $request->validated()),
        ));
    }

    /**
     * Close the current range so a successor rate can take over the following day.
     */
    public function endRange(EndTaxCodeRangeRequest $request, Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('endRange', $taxCode);

        $lastEffectiveDay = CarbonImmutable::parse($request->validated('last_effective_day'))->startOfDay();

        return ApiResponse::item(new TaxCodeResource(
            $this->taxCodes->endRange($taxCode, $lastEffectiveDay),
        ));
    }

    public function deactivate(Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('deactivate', $taxCode);

        return ApiResponse::item(new TaxCodeResource($this->taxCodes->deactivate($taxCode)));
    }

    public function reactivate(Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('reactivate', $taxCode);

        return ApiResponse::item(new TaxCodeResource($this->taxCodes->reactivate($taxCode)));
    }

    /**
     * Remove a tax code created in error.
     *
     * Refused by the service for any code already applied to a document — deactivating is the
     * ordinary path for a code no longer offered.
     */
    public function destroy(Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('delete', $taxCode);

        $this->taxCodes->delete($taxCode);

        return ApiResponse::noContent();
    }

    public function restore(Company $company, TaxCode $taxCode): JsonResponse
    {
        $this->authorize('restore', $taxCode);

        return ApiResponse::item(new TaxCodeResource($this->taxCodes->restore($taxCode)));
    }
}
