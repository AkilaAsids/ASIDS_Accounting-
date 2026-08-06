<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Controllers;

use Asids\Core\Organization\Application\Services\BranchService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Presentation\Http\Requests\StoreBranchRequest;
use Asids\Core\Organization\Presentation\Http\Requests\UpdateBranchRequest;
use Asids\Core\Organization\Presentation\Http\Resources\BranchResource;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branches are nested under their company in the URL, because a branch has no meaning
 * outside one and a flat `/branches/{id}` would invite queries that forget to scope by
 * company.
 */
final class BranchController extends ApiController
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->forCompany((string) $company->getKey())
            ->with('manager:id,first_name,last_name')
            ->when(
                $request->boolean('active_only', true),
                static fn ($query) => $query->active()
            )
            // Primary first: it is the one users pick most, and burying it alphabetically
            // makes every branch selector slower to use.
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return ApiResponse::item(BranchResource::collection($branches)->resolve($request));
    }

    public function show(Company $company, Branch $branch): JsonResponse
    {
        $this->assertBelongsToCompany($company, $branch);
        $this->authorize('view', $branch);

        return ApiResponse::item(new BranchResource($branch->load('manager:id,first_name,last_name')));
    }

    public function store(StoreBranchRequest $request, Company $company): JsonResponse
    {
        $branch = $this->branches->create($company, $request->validated());

        return ApiResponse::created(new BranchResource($branch));
    }

    public function update(UpdateBranchRequest $request, Company $company, Branch $branch): JsonResponse
    {
        $this->assertBelongsToCompany($company, $branch);

        return ApiResponse::item(new BranchResource(
            $this->branches->update($branch, $request->validated())
        ));
    }

    public function archive(Company $company, Branch $branch): JsonResponse
    {
        $this->assertBelongsToCompany($company, $branch);
        $this->authorize('archive', $branch);

        return ApiResponse::item(new BranchResource($this->branches->archive($branch)));
    }

    public function restore(Company $company, Branch $branch): JsonResponse
    {
        $this->assertBelongsToCompany($company, $branch);
        $this->authorize('restore', $branch);

        return ApiResponse::item(new BranchResource($this->branches->restore($branch)));
    }

    /**
     * Move the primary designation, which decides where transactions land when a document
     * does not name a branch.
     */
    public function makePrimary(Company $company, Branch $branch): JsonResponse
    {
        $this->assertBelongsToCompany($company, $branch);
        $this->authorize('makePrimary', $branch);

        return ApiResponse::item(new BranchResource($this->branches->makePrimary($branch)));
    }

    /**
     * Nested route bindings resolve independently, so `/companies/A/branches/{B of company C}`
     * would otherwise load a branch from a different company that the caller happens to have
     * access to. Checked explicitly rather than relying on scoped bindings, so the guarantee
     * does not depend on how the route was registered.
     */
    private function assertBelongsToCompany(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->getKey()) {
            throw BusinessRuleViolation::make(
                code: 'branch-company-mismatch',
                message: 'That branch does not belong to the specified company.',
            );
        }
    }
}
