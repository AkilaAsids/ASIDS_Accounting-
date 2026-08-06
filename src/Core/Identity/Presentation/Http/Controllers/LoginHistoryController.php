<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Domain\Models\LoginHistory;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Resources\LoginHistoryResource;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication history.
 *
 * Two audiences with one endpoint shape: a user reviewing their own sign-ins at `/me/login-history`,
 * and a security reviewer looking across the workspace at `/login-history`. The second requires the
 * `identity.login_history.view` permission and is the one that includes failed attempts against
 * addresses that do not exist — the credential-stuffing signal.
 */
final class LoginHistoryController extends ApiController
{
    public function mine(Request $request): JsonResponse
    {
        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['created_at'],
            filterable: ['outcome'],
            defaultSort: '-created_at',
        );

        $history = LoginHistory::query()
            ->where('user_id', $this->currentUser()->getKey())
            ->applyFilter(
                $criteria->filter('outcome'),
                static fn ($query, string $outcome) => $query->where('outcome', $outcome)
            )
            ->orderByDesc('created_at')
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(LoginHistoryResource::collection($history));
    }

    /**
     * Workspace-wide history, including attempts against unknown addresses.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewLoginHistory', $this->currentUser());

        $criteria = QueryCriteria::fromRequest(
            request: $request,
            sortable: ['created_at'],
            filterable: ['outcome', 'user_id', 'ip_address'],
            defaultSort: '-created_at',
        );

        $history = LoginHistory::query()
            ->with('user:id,first_name,last_name,email')
            ->applyFilter(
                $criteria->filter('outcome'),
                static fn ($query, string $outcome) => $query->where('outcome', $outcome)
            )
            ->applyFilter(
                $criteria->filter('user_id'),
                static fn ($query, string $userId) => $query->where('user_id', $userId)
            )
            ->applyFilter(
                $criteria->filter('ip_address'),
                static fn ($query, string $ip) => $query->where('ip_address', $ip)
            )
            ->orderByDesc('created_at')
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(LoginHistoryResource::collection($history));
    }
}
