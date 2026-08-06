<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Http\Controllers;

use Asids\Core\Audit\Application\Services\AuditChainSealer;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Audit\Presentation\Http\Resources\AuditLogResource;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['created_at', 'sequence'],
            filterable: ['event', 'actor_id', 'auditable_type', 'auditable_id', 'company_id', 'from', 'to'],
            defaultSort: '-sequence',
        );

        $entries = AuditLog::query()
            ->applyFilter($criteria->filter('event'), static fn ($q, string $v) => $q->where('event', $v))
            ->applyFilter($criteria->filter('actor_id'), static fn ($q, string $v) => $q->where('actor_id', $v))
            ->applyFilter($criteria->filter('company_id'), static fn ($q, string $v) => $q->where('company_id', $v))
            ->applyFilter($criteria->filter('auditable_type'), static fn ($q, string $v) => $q->where('auditable_type', $v))
            ->applyFilter($criteria->filter('auditable_id'), static fn ($q, string $v) => $q->where('auditable_id', $v))
            // Date bounds rather than an offset window: an auditor works in "the year ended 31
            // March", and paging back through a million rows to reach it is not viable.
            ->applyFilter($criteria->filter('from'), static fn ($q, string $v) => $q->where('created_at', '>=', $v))
            ->applyFilter($criteria->filter('to'), static fn ($q, string $v) => $q->where('created_at', '<=', $v))
            // Ordered by sequence, not created_at: two entries can share a timestamp to the
            // microsecond, and only the sequence gives a total order that matches the chain.
            ->orderByDesc('sequence')
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(AuditLogResource::collection($entries));
    }

    /**
     * The full history of one record — the question an auditor asks most often.
     */
    public function forRecord(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $entries = AuditLog::query()
            ->forRecord($type, $id)
            ->orderByDesc('sequence')
            ->paginate((int) config('asids.api.pagination.default_per_page'))
            ->withQueryString();

        return ApiResponse::collection(
            collection: AuditLogResource::collection($entries),
            meta: ['record' => ['type' => $type, 'id' => $id]],
        );
    }

    /**
     * Verify the workspace's hash chain and report the first break, if any.
     */
    public function verify(AuditChainSealer $sealer, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('verify', AuditLog::class);

        $result = $sealer->verify($tenantContext->require()->getKey());

        return ApiResponse::item([
            'intact' => $result['intact'],
            'entries_verified' => $result['verified'],
            'failure' => $result['failure'],
            'unsealed_entries' => AuditLog::query()->unsealed()->count(),
            'message' => $result['intact']
                ? 'The audit trail is intact. Every sealed entry matches its hash and follows the previous one.'
                : 'The audit trail has been altered. Preserve a database backup and contact ASIDS support immediately.',
        ]);
    }
}
