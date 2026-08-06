<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Controllers;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Organization\Presentation\Http\Requests\GrantMembershipRequest;
use Asids\Core\Organization\Presentation\Http\Resources\CompanyMembershipResource;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyMembershipController extends ApiController
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', CompanyMembership::class);

        $memberships = CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->with(['user:id,first_name,last_name,email,status', 'grantedBy:id,first_name,last_name'])
            // Revoked memberships are included when asked for, because "who used to have
            // access" is the question an auditor arrives with.
            ->when(
                $request->boolean('active_only', true),
                static fn ($query) => $query->active()
            )
            ->orderByDesc('is_default')
            ->orderBy('joined_at')
            ->get();

        return ApiResponse::item(CompanyMembershipResource::collection($memberships)->resolve($request));
    }

    public function store(GrantMembershipRequest $request, Company $company): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($request->validated('user_id'));

        $branch = null;
        $branchId = $request->validated('branch_id');

        if (is_string($branchId)) {
            /** @var Branch $branch */
            $branch = Branch::query()->findOrFail($branchId);
        }

        $membership = $this->memberships->grant(
            company: $company,
            user: $user,
            grantedBy: $this->currentUser(),
            branch: $branch,
            makeDefault: (bool) $request->validated('make_default', false),
        );

        return ApiResponse::created(new CompanyMembershipResource(
            $membership->load(['user:id,first_name,last_name,email,status'])
        ));
    }

    public function destroy(Company $company, CompanyMembership $membership): JsonResponse
    {
        if ($membership->company_id !== $company->getKey()) {
            throw BusinessRuleViolation::make(
                code: 'membership-company-mismatch',
                message: 'That access grant does not belong to the specified company.',
            );
        }

        $this->authorize('revoke', $membership);

        $this->memberships->revoke($membership, $this->currentUser());

        return ApiResponse::noContent();
    }

    /**
     * Change the company the *current* user lands in.
     *
     * Deliberately self-service and unauthorised beyond membership: choosing your own landing
     * company is a preference, not a privilege, and requiring a permission for it would mean
     * a bookkeeper with two companies has to ask an administrator to switch.
     */
    public function setOwnDefault(Company $company): JsonResponse
    {
        $user = $this->currentUser();

        if (! $user->canAccessCompany((string) $company->getKey())) {
            throw BusinessRuleViolation::make(
                code: 'not-a-member',
                message: 'You do not have access to that company.',
            );
        }

        $membership = $this->memberships->setDefault($user, $company);

        return ApiResponse::item(new CompanyMembershipResource($membership));
    }
}
