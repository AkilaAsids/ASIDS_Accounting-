<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Controllers;

use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Presentation\Http\Requests\StoreCompanyRequest;
use Asids\Core\Organization\Presentation\Http\Requests\UpdateCompanyRequest;
use Asids\Core\Organization\Presentation\Http\Resources\CompanyResource;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyController extends ApiController
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['name', 'code', 'created_at', 'status'],
            filterable: ['status'],
            defaultSort: 'name',
        );

        $companies = Company::query()
            // Membership-scoped, not merely tenant-scoped: a workspace administrator who is
            // not a member of a group company must not see its details in the list.
            ->accessibleBy($this->currentUser())
            ->withCount(['branches', 'memberships'])
            ->applyFilter(
                $criteria->filter('status'),
                static fn ($query, string $status) => $query->where('status', $status)
            )
            ->applyFilter(
                $criteria->search(),
                static fn ($query, string $term) => $query->where(
                    static fn ($inner) => $inner
                        ->whereRaw('name ILIKE ?', ["%{$term}%"])
                        ->orWhereRaw('upper(code) LIKE ?', [strtoupper("%{$term}%")])
                )
            )
            ->tap(static function ($query) use ($criteria): void {
                foreach ($criteria->sorts() as $column => $direction) {
                    $query->orderBy($column, $direction);
                }
            })
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(CompanyResource::collection($companies));
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return ApiResponse::item(new CompanyResource(
            $company->load(['branches' => static fn ($query) => $query->orderByDesc('is_primary')->orderBy('name')])
        ));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->create(
            CreateCompanyData::fromArray($request->validated()),
            $this->currentUser(),
        );

        return ApiResponse::created(new CompanyResource($company->load('branches')));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $updated = $this->companies->update(
            $company,
            $request->validated(),
            $this->currentUser(),
        );

        return ApiResponse::item(new CompanyResource($updated->load('branches')));
    }

    /**
     * Close a company to further activity. Never a delete — see OrganizationStatus.
     */
    public function archive(Company $company): JsonResponse
    {
        $this->authorize('archive', $company);

        $archived = $this->companies->archive($company, $this->currentUser());

        return ApiResponse::item(new CompanyResource($archived));
    }

    public function restore(Company $company): JsonResponse
    {
        $this->authorize('restore', $company);

        $restored = $this->companies->restore($company, $this->currentUser());

        return ApiResponse::item(new CompanyResource($restored));
    }

    /**
     * Make this the company new users land in.
     */
    public function makeDefault(Company $company): JsonResponse
    {
        $this->authorize('makeDefault', $company);

        return ApiResponse::item(new CompanyResource(
            $this->companies->makeDefault($company)
        ));
    }
}
