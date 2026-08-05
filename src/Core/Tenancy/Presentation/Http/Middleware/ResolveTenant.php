<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Presentation\Http\Middleware;

use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Application\Services\TenantResolver;
use Asids\Core\Tenancy\Domain\Exceptions\TenantUnavailable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes tenant context from the request, before authentication.
 *
 * Order matters and is the reason this runs so early: the users table is tenant
 * scoped, so the guard cannot look up the authenticating user until the tenant is
 * known. Resolving after authentication would mean either an unscoped user lookup
 * (a cross-tenant login) or a chicken-and-egg failure.
 *
 * A request that resolves to no tenant is *not* rejected here. Central endpoints —
 * sign-in for platform staff, the health check, tenant sign-up — legitimately have
 * no tenant, and it is the route's own middleware (`tenant`) that requires one.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            // Guarantee a clean slate: on a long-lived worker or an Octane-style
            // runtime, a previous request's tenant could otherwise still be active.
            $this->context->end();

            return $next($request);
        }

        // A tenant that is provisioning, suspended or closed is identified but not
        // served. Checking here rather than in each controller means no endpoint
        // can be reached by a suspended workspace by accident.
        if (! $tenant->status->permitsAccess()) {
            throw TenantUnavailable::because($tenant->status);
        }

        $this->context->initialize($tenant);

        /** @var Response $response */
        $response = $next($request);

        // The tenant slug is echoed so a client (and a support engineer reading a
        // HAR file) can confirm which workspace served the request.
        $response->headers->set('X-Tenant', $tenant->slug);

        return $response;
    }
}
