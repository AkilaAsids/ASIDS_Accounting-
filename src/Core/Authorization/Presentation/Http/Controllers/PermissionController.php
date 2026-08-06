<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Controllers;

use Asids\Core\Authorization\Domain\Models\Permission;
use Asids\Core\Authorization\Presentation\Http\Resources\PermissionResource;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PermissionController extends ApiController
{
    /**
     * The capability catalogue, grouped for the permission matrix.
     *
     * Returned grouped by module and resource because that is exactly how the matrix is
     * rendered; making the client regroup a flat list would put the grouping rules in
     * two places, and the two would eventually disagree about where a new module belongs.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        $permissions = Permission::query()
            ->grantableToTenants()
            ->orderBy('module')
            ->orderBy('sort_order')
            ->get();

        $grouped = $permissions
            ->groupBy('module')
            ->map(static fn (Collection $modulePermissions, string $module): array => [
                'module' => $module,
                'resources' => $modulePermissions
                    ->groupBy('resource')
                    ->map(static fn (Collection $resourcePermissions, string $resource): array => [
                        'resource' => $resource,
                        'permissions' => PermissionResource::collection($resourcePermissions)
                            ->resolve($request),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::item(
            data: $grouped,
            meta: ['total' => $permissions->count()],
        );
    }
}
