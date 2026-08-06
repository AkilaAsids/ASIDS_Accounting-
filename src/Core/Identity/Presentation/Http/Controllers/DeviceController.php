<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\DeviceService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Domain\Models\UserDevice;
use Asids\Core\Identity\Presentation\Http\Resources\UserDeviceResource;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Where am I signed in?" — and the ability to revoke a device the user no longer controls.
 *
 * Nested under a user so an administrator can inspect a colleague's devices during an incident,
 * while a user reaches their own through `/me/devices`.
 */
final class DeviceController extends ApiController
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(Request $request, ?User $user = null): JsonResponse
    {
        $target = $user ?? $this->currentUser();

        $this->authorize('viewDevices', $target);

        $devices = UserDevice::query()
            ->where('user_id', $target->getKey())
            // Revoked devices are included when asked for: "which device was signed in last
            // Tuesday" is a question an incident response needs answered.
            ->when($request->boolean('active_only', true), static fn ($query) => $query->whereNull('revoked_at'))
            ->orderByDesc('last_seen_at')
            ->get();

        return ApiResponse::item(UserDeviceResource::collection($devices)->resolve($request));
    }

    public function destroy(UserDevice $device): JsonResponse
    {
        $owner = $device->user;

        $this->authorize('revokeDevice', $owner);

        if ($device->revoked_at !== null) {
            throw BusinessRuleViolation::make(
                code: 'device-already-revoked',
                message: 'That device has already been revoked.',
            );
        }

        return ApiResponse::item(new UserDeviceResource(
            $this->devices->revoke($device, $this->currentUser())
        ));
    }
}
