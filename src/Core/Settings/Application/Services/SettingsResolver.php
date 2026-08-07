<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Application\Services;

use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;

/**
 * Reads settings, resolving user → company → tenant → system → code default.
 *
 * PERFORMANCE
 * -----------
 * Settings are read on nearly every request — the interface cannot render a date without one —
 * so the naive implementation (a query per key per request) is not viable. Instead the whole
 * override set for a scope is loaded once, cached, and resolved in memory. A request that reads
 * twenty settings issues at most three cache reads and, on a warm cache, no queries at all.
 *
 * The cache is already tenant-prefixed by CacheTagBootstrapper, so one workspace can never read
 * another's overrides even though the key names are identical.
 */
final class SettingsResolver
{
    private const int CACHE_TTL_SECONDS = 3600;

    /**
     * Per-request memoisation on top of the cache. Twenty reads of `localisation.date_format`
     * while rendering a table should cost one cache read, not twenty.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $loaded = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantContext $tenantContext,
    ) {}

    public function get(string $key, ?string $userId = null, ?string $companyId = null): mixed
    {
        $definition = SettingsCatalogue::find($key);

        if ($definition === null) {
            // An unknown key is a programming error, not user input — every read goes through a
            // string literal in code. Returning null would hide it until someone noticed a
            // feature quietly using a falsy default.
            throw new InvalidArgumentException("Unknown setting key: {$key}");
        }

        foreach ($definition->resolutionScopes() as $scope) {
            $target = match ($scope) {
                SettingScope::User => $userId,
                SettingScope::Company => $companyId,
                default => null,
            };

            // A scope whose target is unknown for this request is skipped rather than treated as
            // "no override": resolving a company setting with no company would otherwise fall
            // through to the workspace value and look correct.
            if ($scope->requiresTarget() && $target === null) {
                continue;
            }

            $overrides = $this->overridesFor($scope, $target);

            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }
        }

        return $definition->default;
    }

    /**
     * Every setting in a group, resolved. Used by the settings screen, which needs all of them
     * and must not issue one lookup per field.
     *
     * @return array<string, mixed>
     */
    public function group(string $group, ?string $userId = null, ?string $companyId = null): array
    {
        $resolved = [];

        foreach (SettingsCatalogue::all() as $definition) {
            if ($definition->group === $group) {
                $resolved[$definition->key] = $this->get($definition->key, $userId, $companyId);
            }
        }

        return $resolved;
    }

    /**
     * The settings the interface needs in order to render, resolved for the current user.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(?string $userId = null, ?string $companyId = null): array
    {
        $resolved = [];

        foreach (SettingsCatalogue::publicKeys() as $key) {
            $resolved[$key] = $this->get($key, $userId, $companyId);
        }

        return $resolved;
    }

    /**
     * Drop the cached override set for one scope.
     *
     * Targeted rather than a wildcard flush: a `KEYS settings:*` scan blocks Redis, and flushing
     * the whole cache to invalidate one setting would evict every tenant's permission set with it.
     */
    public function forget(SettingScope $scope, ?string $scopeId = null): void
    {
        $key = $this->cacheKey($scope, $scopeId);

        unset($this->loaded[$key]);
        $this->cache->forget($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function overridesFor(SettingScope $scope, ?string $scopeId): array
    {
        $cacheKey = $this->cacheKey($scope, $scopeId);

        if (array_key_exists($cacheKey, $this->loaded)) {
            return $this->loaded[$cacheKey];
        }

        /** @var array<string, mixed> $overrides */
        $overrides = $this->cache->remember(
            key: $cacheKey,
            ttl: self::CACHE_TTL_SECONDS,
            callback: function () use ($scope, $scopeId): array {
                $resolved = [];

                Setting::query()
                    ->withoutGlobalScopes()
                    ->when(
                        $scope === SettingScope::System,
                        static fn ($query) => $query->whereNull('tenant_id'),
                        fn ($query) => $query->where('tenant_id', $this->tenantContext->id()),
                    )
                    ->atScope($scope, $scopeId)
                    ->get()
                    ->each(function (Setting $setting) use (&$resolved): void {
                        // A row whose key has since left the catalogue is skipped rather than
                        // returned: nothing reads it, and surfacing it would put a value into the
                        // settings screen that no code understands.
                        if ($setting->definition() === null) {
                            return;
                        }

                        $resolved[$setting->key] = $setting->resolvedValue();
                    });

                return $resolved;
            },
        );

        return $this->loaded[$cacheKey] = $overrides;
    }

    private function cacheKey(SettingScope $scope, ?string $scopeId): string
    {
        return implode(':', array_filter([
            'settings',
            $scope->value,
            $scope === SettingScope::System ? 'platform' : ($this->tenantContext->id() ?? 'central'),
            $scopeId,
        ]));
    }
}
