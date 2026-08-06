<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Http\Controllers;

use Asids\Core\Audit\Domain\Models\ActivityLog;
use Asids\Core\Audit\Presentation\Http\Resources\ActivityLogResource;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard activity feed.
 */
final class ActivityLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['created_at'],
            filterable: ['channel', 'causer_id', 'company_id'],
            defaultSort: '-created_at',
        );

        $activities = ActivityLog::query()
            ->applyFilter($criteria->filter('channel'), static fn ($q, string $v) => $q->inChannel($v))
            ->applyFilter($criteria->filter('causer_id'), static fn ($q, string $v) => $q->where('causer_id', $v))
            ->applyFilter($criteria->filter('company_id'), static fn ($q, string $v) => $q->where('company_id', $v))
            ->orderByDesc('created_at')
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(ActivityLogResource::collection($activities));
    }
}
