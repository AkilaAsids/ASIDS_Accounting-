<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees that the API never answers with HTML.
 *
 * Without this, a client that forgets the `Accept` header receives Laravel's HTML
 * error page for a 419 or a 500 — which a JSON parser turns into an unhelpable
 * "unexpected token <" in the browser console. Forcing the header makes the
 * failure mode legible.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
