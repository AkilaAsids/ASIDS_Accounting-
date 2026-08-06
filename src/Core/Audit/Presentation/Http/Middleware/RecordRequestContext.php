<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Presentation\Http\Middleware;

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Completes the request context with facts that only become known after authentication.
 *
 * `AssignRequestId` runs first and establishes the correlation id, but the actor, the
 * impersonator and the access token are unknown until the guard has resolved a user. Filling
 * them here means every audit entry and log line written downstream is attributed without any
 * call site having to pass an actor around.
 *
 * Placed last in the API middleware stack, so it sees the authenticated user; the audit entries
 * it enriches are written later still, inside the controllers.
 */
final class RecordRequestContext
{
    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {

        $user = $request->user();

        if ($user instanceof User) {
            $this->context->setActorId((string) $user->getKey());

            $token = $user->currentAccessToken();

            // `instanceof`, not a null check. On a cookie-authenticated request Sanctum returns a
            // TransientToken — a stand-in with no id, no abilities and no `getKey()` — so testing
            // for null and then calling `getKey()` fatals on every SPA request that reaches this
            // middleware.
            if ($token instanceof PersonalAccessToken) {
                // Recorded so a compromised integration's activity can be isolated from the
                // owner's own interactive work — which is impossible if both appear as the
                // same actor.
                $this->context->setAccessTokenId((string) $token->getKey());
                $this->context->setChannel('api');
            }

            // Set by the impersonation flow when an ASIDS operator is acting as the user. Read
            // from the session rather than the request so it cannot be spoofed by a header.
            if ($request->hasSession()) {
                $impersonator = $request->session()->get('asids_impersonator_id');

                if (is_string($impersonator)) {
                    $this->context->setImpersonatorId($impersonator);
                }
            }
        }

        return $next($request);
    }
}
