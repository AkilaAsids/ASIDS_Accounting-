<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Controllers;

use Asids\Core\Identity\Application\Services\AccessTokenService;
use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Presentation\Http\Requests\StoreAccessTokenRequest;
use Asids\Core\Identity\Presentation\Http\Resources\AccessTokenResource;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal access tokens for integrations and mobile clients.
 *
 * Route is step-up protected: issuing a token creates a long-lived credential, so a hijacked
 * session must not be sufficient to mint one.
 */
final class AccessTokenController extends ApiController
{
    public function __construct(private readonly AccessTokenService $tokens) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PersonalAccessToken::class);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_id', $this->currentUser()->getKey())
            ->with('creator:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::item(AccessTokenResource::collection($tokens)->resolve($request));
    }

    public function store(StoreAccessTokenRequest $request): JsonResponse
    {
        /** @var list<string> $abilities */
        $abilities = $request->validated('abilities');
        /** @var list<string> $ranges */
        $ranges = $request->validated('allowed_ip_ranges', []);

        $result = $this->tokens->issue(
            owner: $this->currentUser(),
            name: (string) $request->validated('name'),
            requestedAbilities: $abilities,
            description: $request->validated('description'),
            expiresInDays: $request->validated('expires_in_days'),
            allowedIpRanges: $ranges,
            createdIp: $request->ip(),
        );

        return ApiResponse::created(
            data: new AccessTokenResource($result['token']),
            meta: [
                // The only time the plaintext is ever returned. It is not recoverable afterwards,
                // and the notice says so because a user who loses it will otherwise open a ticket.
                'plaintext_token' => $result['plaintext'],
                'notice' => 'Copy this token now. It cannot be shown again — if you lose it, revoke it and create another.',
                // Surfaced so a caller can see when the intersection with their own permissions
                // reduced what they asked for, rather than discovering it as a 403 later.
                'granted_abilities' => $result['token']->abilities ?? [],
            ],
        );
    }

    public function destroy(Request $request, PersonalAccessToken $token): JsonResponse
    {
        $this->authorize('delete', $token);

        return ApiResponse::item(new AccessTokenResource(
            $this->tokens->revoke(
                $token,
                $this->currentUser(),
                trim((string) $request->input('reason', '')) ?: 'revoked_by_user',
            )
        ));
    }
}
