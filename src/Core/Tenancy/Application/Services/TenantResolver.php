<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Application\Services;

use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Exceptions\TenantNotFound;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Determines which tenant a request belongs to.
 *
 * Two mechanisms, in priority order:
 *
 *   1. Hostname. `acme.erp.asidstech.com`, or a verified customer-owned hostname.
 *      This is how the browser SPA arrives, and it is the mechanism that makes
 *      cookies naturally tenant scoped.
 *
 *   2. `X-Tenant` header carrying the tenant slug. This is for API clients, which
 *      often cannot vary their base URL per customer, and for local development
 *      where wildcard DNS is inconvenient.
 *
 * Resolution is cached because it happens on every single request and would
 * otherwise be a guaranteed database round trip before any useful work. The cache
 * is *central* (not tenant scoped) and short lived, and it is invalidated by the
 * tenant model's own observer when a slug, hostname or status changes — a stale
 * "active" verdict for a suspended tenant is the one error worth engineering
 * against.
 */
final readonly class TenantResolver
{
    public function __construct(
        private TenantRepositoryContract $tenants,
        private CacheRepository $cache,
    ) {}

    public function resolve(Request $request): ?Tenant
    {
        $mode = (string) config('asids.tenancy.identification', 'both');

        if ($mode === 'header' || $mode === 'both') {
            $tenant = $this->fromHeader($request);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        if ($mode === 'subdomain' || $mode === 'both') {
            return $this->fromHost($request->getHost());
        }

        return null;
    }

    /**
     * Forget every cached path to a tenant. Called by TenantObserver.
     */
    public function forget(Tenant $tenant): void
    {
        $this->cache->forget('tenant:slug:'.strtolower($tenant->slug));

        // Queried rather than read from the relation: this runs inside a model
        // observer where the relation is usually not loaded, and lazy loading is
        // prohibited in production.
        /** @var list<string> $domains */
        $domains = $tenant->domains()->pluck('domain')->all();

        foreach ($domains as $domain) {
            $this->cache->forget('tenant:domain:'.strtolower($domain));
        }
    }

    private function fromHeader(Request $request): ?Tenant
    {
        $header = (string) config('asids.tenancy.header', 'X-Tenant');
        $slug = trim((string) $request->header($header, ''));

        if ($slug === '') {
            return null;
        }

        // The header is attacker-controlled, so it is validated against the same
        // DNS-label shape the database enforces before it reaches a query.
        if (! $this->isValidSlug($slug)) {
            throw TenantNotFound::forIdentifier($slug);
        }

        $tenant = $this->rememberBySlug(strtolower($slug));

        // An unknown slug supplied explicitly is an error worth reporting; an
        // unresolvable *hostname*, by contrast, may simply be the central domain.
        if ($tenant === null) {
            throw TenantNotFound::forIdentifier($slug);
        }

        return $tenant;
    }

    private function fromHost(string $host): ?Tenant
    {
        $host = strtolower($host);

        if ($this->isCentralDomain($host)) {
            return null;
        }

        // Custom hostnames are looked up whole; platform subdomains are reduced to
        // their leading label, which keeps the common case a single-key lookup.
        $tenant = $this->rememberByDomain($host);

        if ($tenant !== null) {
            return $tenant;
        }

        $slug = $this->extractSubdomain($host);

        return $slug === null ? null : $this->rememberBySlug($slug);
    }

    private function extractSubdomain(string $host): ?string
    {
        $central = strtolower((string) config('asids.tenancy.central_domain', 'localhost'));

        if (! Str::endsWith($host, '.'.$central)) {
            return null;
        }

        $label = Str::before($host, '.'.$central);

        // Only a single label is accepted: `a.b.central` is not tenant `a`, it is
        // an unknown host. Treating it as `a` would let one tenant be reached on
        // infinitely many hostnames, breaking cookie scoping and cache keys.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        /** @var list<string> $reserved */
        $reserved = config('asids.tenancy.reserved_slugs', []);

        return in_array($label, $reserved, true) ? null : $label;
    }

    private function isCentralDomain(string $host): bool
    {
        /** @var list<string> $central */
        $central = config('tenancy.central_domains', []);

        return in_array($host, array_map('strtolower', $central), true);
    }

    private function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $slug) === 1;
    }

    private function rememberBySlug(string $slug): ?Tenant
    {
        return $this->remember("tenant:slug:{$slug}", fn (): ?Tenant => $this->tenants->findBySlug($slug));
    }

    private function rememberByDomain(string $domain): ?Tenant
    {
        return $this->remember("tenant:domain:{$domain}", fn (): ?Tenant => $this->tenants->findByDomain($domain));
    }

    /**
     * Cache keys are flushed by TenantObserver whenever a slug, hostname or status
     * changes, so the only staleness window is a change made directly in the
     * database — which is not a supported operation.
     *
     * @param  callable(): ?Tenant  $resolver
     */
    private function remember(string $key, callable $resolver): ?Tenant
    {
        $ttl = (int) config('asids.tenancy.resolution_cache_ttl', 300);

        if ($ttl <= 0) {
            return $resolver();
        }

        // The row is cached, not just its id: caching the id alone would still
        // cost one query per request and gain nothing. A negative result is cached
        // too, because traffic to non-existent subdomains — which is what a
        // scanner produces — would otherwise be an uncached query every time.
        /** @var array{attributes: array<string, mixed>|null} $cached */
        $cached = $this->cache->remember($key, $ttl, static function () use ($resolver): array {
            $tenant = $resolver();

            return ['attributes' => $tenant?->getAttributes()];
        });

        $attributes = $cached['attributes'] ?? null;

        if ($attributes === null) {
            return null;
        }

        // `newFromBuilder` hydrates from raw database values without marking the
        // model dirty, which is exactly what a cached row is.
        $tenant = (new Tenant)->newFromBuilder($attributes);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
