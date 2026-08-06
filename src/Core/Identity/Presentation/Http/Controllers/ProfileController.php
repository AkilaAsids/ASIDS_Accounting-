<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Presentation\Http\Requests\UpdateProfileRequest;
use Asids\Core\Identity\Presentation\Http\Resources\UserResource;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The signed-in user's own profile and preferences.
 *
 * Separate from UserController because the permitted field set is different, not merely
 * narrower-by-authorisation: a user may change their own display name and theme, but their
 * employee number and job title are HR records someone else maintains.
 */
final class ProfileController extends ApiController
{
    public function __construct(private readonly UserService $users) {}

    public function show(): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::item(new UserResource(
            $user->load(['roles:id,name,label,level,is_owner', 'defaultCompany:id,name,code', 'tenant:id,timezone,locale'])
                ->loadCount('memberships')
        ));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return ApiResponse::item(new UserResource(
            $this->users->update($this->currentUser(), $request->validated())
        ));
    }
}
