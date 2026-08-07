<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\DTOs\CreateUserData;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Requests\StoreUserRequest;
use Asids\Core\Identity\Presentation\Http\Requests\UpdateUserRequest;
use Asids\Core\Identity\Presentation\Http\Resources\UserResource;
use Asids\Core\Identity\Presentation\Notifications\PasswordResetLink;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace user administration.
 *
 * Status transitions and credential resets are separate endpoints rather than fields on the
 * update payload, because each is a distinct privileged action that must be authorised and
 * audited on its own terms.
 */
final class UserController extends ApiController
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['first_name', 'last_name', 'email', 'created_at', 'last_login_at', 'status'],
            filterable: ['status', 'role', 'company_id'],
            defaultSort: 'last_name',
        );

        $users = User::query()
            ->with(['roles:id,name,label,level,is_owner', 'tenant:id,timezone,locale'])
            ->withCount('memberships')
            ->applyFilter(
                $criteria->filter('status'),
                static fn ($query, string $status) => $query->where('status', $status)
            )
            ->applyFilter(
                $criteria->filter('role'),
                static fn ($query, string $role) => $query->whereHas(
                    'roles',
                    static fn ($inner) => $inner->where('name', $role)
                )
            )
            ->applyFilter(
                $criteria->filter('company_id'),
                static fn ($query, string $companyId) => $query->whereHas(
                    'memberships',
                    static fn ($inner) => $inner->where('company_id', $companyId)
                )
            )
            ->applyFilter(
                $criteria->search(),
                static fn ($query, string $term) => $query->search($term)
            )
            ->tap(static function ($query) use ($criteria): void {
                foreach ($criteria->sorts() as $column => $direction) {
                    $query->orderBy($column, $direction);
                }
            })
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(
            collection: UserResource::collection($users),
            meta: [
                // Seat usage belongs with the list, because the administrator deciding whether to
                // invite someone is looking at exactly this screen.
                'seats' => [
                    'consumed' => User::query()->whereIn('status', [
                        UserStatus::Active->value,
                        UserStatus::PendingInvitation->value,
                    ])->count(),
                    'limit' => $this->currentUser()->tenant?->userLimit(),
                ],
            ],
        );
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::item(new UserResource(
            $user->load(['roles:id,name,label,level,is_owner', 'defaultCompany:id,name,code', 'tenant:id,timezone,locale'])
                ->loadCount('memberships')
        ));
    }

    /**
     * Invite a user. No password is set here — the invitee chooses their own.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->invite(
            CreateUserData::fromArray($request->validated()),
            $this->currentUser(),
        );

        return ApiResponse::created(
            data: new UserResource($user->load('roles:id,name,label,level,is_owner')),
            meta: ['invitation_sent' => true],
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return ApiResponse::item(new UserResource(
            $this->users->update($user, $request->validated())
        ));
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize('suspend', $user);

        $reason = trim((string) $request->input('reason', ''));

        return ApiResponse::item(new UserResource(
            $this->users->suspend($user, $reason === '' ? 'No reason given.' : $reason, $this->currentUser())
        ));
    }

    public function reinstate(User $user): JsonResponse
    {
        $this->authorize('reinstate', $user);

        return ApiResponse::item(new UserResource($this->users->reinstate($user)));
    }

    /**
     * The terminal state. Access ends; the identity stays resolvable for audit attribution, which
     * is why there is no `destroy` on this controller.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('deactivate', $user);

        $reason = trim((string) $request->input('reason', ''));

        return ApiResponse::item(new UserResource(
            $this->users->deactivate($user, $reason === '' ? 'No reason given.' : $reason, $this->currentUser())
        ));
    }

    /**
     * Send the user a reset link. An administrator never sets another user's password, so no
     * administrator ever knows a colleague's credential.
     */
    public function sendPasswordReset(User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $url = $this->users->sendPasswordResetLink($user);

        $user->notify(new PasswordResetLink($url));

        return ApiResponse::accepted('A password reset link has been sent to the user.');
    }

    /**
     * Clear a user's second factor so they can re-enrol. Step-up protected on the route.
     */
    public function resetTwoFactor(User $user): JsonResponse
    {
        $this->authorize('resetTwoFactor', $user);

        return ApiResponse::item(new UserResource(
            $this->users->resetTwoFactor($user, $this->currentUser())
        ));
    }
}
