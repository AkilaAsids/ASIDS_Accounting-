<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Controllers;

use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Application\Services\RoleService;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Authorization\Presentation\Http\Requests\AssignRolesRequest;
use Asids\Core\Authorization\Presentation\Http\Requests\StoreRoleRequest;
use Asids\Core\Authorization\Presentation\Http\Requests\UpdateRoleRequest;
use Asids\Core\Authorization\Presentation\Http\Resources\RoleResource;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RoleController extends ApiController
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        // Eager loading both the permissions and the assignment count: the roles screen
        // renders every role with its permission set and its usage, and without these
        // two it is a guaranteed N+1 on a page that a customer opens often.
        $roles = Role::query()
            ->with('permissions:id,name')
            // A subquery rather than `withCount('users')`. spatie resolves the `users` relation's
            // model from the role's `guard_name`, which is null on a query builder rather than a
            // hydrated instance — so withCount fataled with "Class name must be a valid object or
            // a string". Counting the pivot directly also counts every assignment regardless of
            // the model type, which is what "how many users hold this role" actually means.
            ->addSelect(['users_count' => DB::table('model_has_roles')
                ->selectRaw('count(*)')
                ->whereColumn('model_has_roles.role_id', 'roles.id'),
            ])
            ->orderByDesc('level')
            ->orderBy('label')
            ->get();

        return ApiResponse::item(
            data: RoleResource::collection($roles)->resolve($request),
            meta: [
                // The actor's own level bounds every control the UI offers, so it is sent
                // once rather than inferred per row.
                'actor_role_level' => $this->currentUser()->highestRoleLevel(),
                'actor_is_owner' => $this->currentUser()->isTenantOwner(),
            ],
        );
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return ApiResponse::item(new RoleResource($role->load('permissions:id,name')));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create(
            RoleData::fromArray($request->validated()),
            $this->currentUser(),
        );

        return ApiResponse::created(new RoleResource($role->load('permissions:id,name')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roles->update(
            $role,
            RoleData::fromArray($request->validated()),
            $this->currentUser(),
        );

        return ApiResponse::item(new RoleResource($updated->load('permissions:id,name')));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roles->delete($role);

        return ApiResponse::noContent();
    }

    /**
     * Replace a user's roles.
     *
     * Nested under the user rather than the role because the operation is a full
     * replacement of one user's set — modelling it as "add role to role's user list"
     * would make the atomic replacement impossible to express.
     */
    public function assign(AssignRolesRequest $request, User $user): JsonResponse
    {
        /** @var list<string> $roleIds */
        $roleIds = $request->validated('role_ids', []);

        $updated = $this->roles->assign($user, $roleIds, $this->currentUser());

        return ApiResponse::item([
            'user_id' => $updated->getKey(),
            'roles' => $updated->tenantRoles()->map(static fn (Role $role): array => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'label' => $role->label,
            ])->values()->all(),
        ]);
    }

    /**
     * Hand workspace ownership to another user.
     *
     * Guarded by `two-factor` middleware on the route: this is the single most
     * consequential action in the product, and a hijacked session must not be enough to
     * perform it.
     */
    public function transferOwnership(Request $request, User $user): JsonResponse
    {
        $this->authorize('transferOwnership', Role::class);

        $this->roles->transferOwnership(
            from: $this->currentUser(),
            to: $user,
            actor: $this->currentUser(),
        );

        return ApiResponse::item([
            'message' => 'Workspace ownership transferred.',
            'new_owner_id' => $user->getKey(),
        ]);
    }
}
