<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Middleware;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Exceptions\NoCompanyAccess;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which company the request operates on.
 *
 * Applied to route groups that need one — not globally — because plenty of endpoints
 * (profile, sign-out, the company list itself) are workspace-level and must remain reachable
 * by a user who has not yet been granted access to any company.
 *
 * Resolution order, most explicit first:
 *
 *   1. The `company` route parameter, so `/companies/{company}/branches` is self-describing.
 *   2. The `X-Company` header, which is how the SPA's company switcher works without
 *      rewriting every URL.
 *   3. The user's default company.
 *
 * Whichever wins, membership is verified. That check is the reason this middleware exists
 * rather than each controller reading the header: a header is client-supplied, and trusting
 * it would let any authenticated user read any company in their workspace.
 */
final class ResolveActiveCompany
{
    public const string ATTRIBUTE = 'asids_active_company';

    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Authentication is a separate middleware's job; reaching here unauthenticated
            // means the route is misconfigured, and failing closed is the safe response.
            throw NoCompanyAccess::atAll();
        }

        $company = $this->resolve($request, $user);

        // Bound to the request so controllers and form requests read the *verified* company
        // rather than re-reading the header, and stamped onto the request context so audit
        // entries and log lines carry it without any call site remembering to pass it.
        $request->attributes->set(self::ATTRIBUTE, $company);
        $this->context->setCompanyId((string) $company->getKey());

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set('X-Company', (string) $company->getKey());
        }

        return $response;
    }

    private function resolve(Request $request, User $user): Company
    {
        $requested = $this->requestedCompanyId($request);

        if ($requested !== null) {
            // Scoped by membership in the query itself, so an inaccessible company is
            // indistinguishable from a non-existent one and cannot be enumerated.
            $company = Company::query()
                ->accessibleBy($user)
                ->active()
                ->whereKey($requested)
                ->first();

            if ($company === null) {
                throw NoCompanyAccess::toCompany();
            }

            return $company;
        }

        if ($user->default_company_id !== null) {
            $company = Company::query()
                ->accessibleBy($user)
                ->active()
                ->whereKey($user->default_company_id)
                ->first();

            if ($company !== null) {
                return $company;
            }
        }

        // The default may have been archived or the membership revoked since it was set, so
        // fall back to any accessible company rather than refusing the request.
        $fallback = Company::query()
            ->accessibleBy($user)
            ->active()
            ->orderBy('name')
            ->first();

        if ($fallback === null) {
            throw NoCompanyAccess::atAll();
        }

        return $fallback;
    }

    private function requestedCompanyId(Request $request): ?string
    {
        $route = $request->route();
        $parameter = $route?->parameter('company');

        if ($parameter instanceof Company) {
            return (string) $parameter->getKey();
        }

        if (is_string($parameter) && $parameter !== '') {
            return $parameter;
        }

        $header = trim((string) $request->header('X-Company', ''));

        // Validated as a UUID before it reaches a query: an unvalidated value would produce
        // a PostgreSQL type error rendered as a 500 rather than a clean 404.
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $header
        ) === 1 ? $header : null;
    }
}
