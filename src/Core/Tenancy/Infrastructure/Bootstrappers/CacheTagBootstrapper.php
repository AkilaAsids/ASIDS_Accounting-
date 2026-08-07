<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Namespaces the cache per tenant.
 *
 * A shared Redis instance holding one hundred thousand tenants' cached chart of
 * accounts, permission sets and dashboard aggregates needs two guarantees: one
 * tenant can never read another's cached value, and one tenant's cache can be
 * flushed without touching anyone else's.
 *
 * Prefixing (rather than Redis tag sets) is used because prefix lookups are O(1)
 * and tag sets grow unboundedly; the per-tenant flush is implemented by the
 * Settings and Authorization modules as targeted key deletion rather than a
 * wildcard scan, which would block Redis on a large keyspace.
 */
final class CacheTagBootstrapper implements TenancyBootstrapper
{
    private ?string $originalPrefix = null;

    public function __construct(
        private readonly Application $app,
        private readonly CacheManager $cache,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix ??= (string) config('cache.prefix');

        $this->swapPrefix(sprintf(
            '%s:%s:%s',
            $this->originalPrefix,
            (string) config('tenancy.cache.tag_base', 'tenant'),
            (string) $tenant->getTenantKey(),
        ));
    }

    public function revert(): void
    {
        if ($this->originalPrefix === null) {
            return;
        }

        $this->swapPrefix($this->originalPrefix);
    }

    private function swapPrefix(string $prefix): void
    {
        config(['cache.prefix' => $prefix]);

        // Forgetting the resolved stores is what makes the new prefix take
        // effect: an already-instantiated repository has the old prefix baked in.
        $this->cache->forgetDriver(array_keys((array) config('cache.stores')));

        $this->app->forgetInstance('cache.store');
    }
}
