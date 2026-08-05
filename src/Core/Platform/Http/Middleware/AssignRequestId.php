<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Http\Middleware;

use Asids\Core\Platform\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation identifier for the request, first thing.
 *
 * A caller-supplied `X-Request-Id` is honoured when it is a well formed UUID so
 * that a mobile client or an upstream gateway can correlate its own logs with
 * ours; anything else is replaced rather than trusted, because the value ends up
 * in log lines and an unvalidated one is a log-injection vector.
 */
final class AssignRequestId
{
    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->adoptRequestId($request->header('X-Request-Id'));
        $this->context->setChannel($this->detectChannel($request));

        // Bind the identifiers to the logger for the remainder of the request, so
        // no call site has to remember to pass them.
        Log::withContext($this->context->toArray());

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $this->context->requestId());

        return $response;
    }

    private function detectChannel(Request $request): string
    {
        if ($request->hasHeader('Authorization')) {
            return 'api';
        }

        $agent = (string) $request->userAgent();

        return str_contains($agent, 'ASIDS-Mobile') ? 'mobile' : 'web';
    }
}
