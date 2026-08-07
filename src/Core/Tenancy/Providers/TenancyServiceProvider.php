<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Providers;

use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Application\Services\TenantResolver;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Domain\Scopes\TenantScope;
use Asids\Core\Tenancy\Infrastructure\Observers\TenantObserver;
use Asids\Core\Tenancy\Infrastructure\Repositories\EloquentTenantRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantRepositoryContract::class, EloquentTenantRepository::class);

        // The scope holds a TenantContext, and every tenant-scoped model resolves
        // it from the container at boot. A singleton keeps that to one instance
        // rather than one per model class.
        $this->app->singleton(TenantScope::class);
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Tenant::observe(TenantObserver::class);

        $this->registerTenancyLifecycle();
    }

    /**
     * Wires stancl/tenancy's lifecycle events to the bootstrappers.
     *
     * WITHOUT THIS NOTHING WORKS, AND NOTHING COMPLAINS.
     *
     * The package fires TenancyInitialized and TenancyEnded but does not itself listen to
     * them — the listener registration is the application's job, normally via a published
     * provider. Omit it and `tenancy()->initialize()` succeeds, `tenancy()->tenant` is set,
     * the Eloquent scope works, and every test that only exercises the scope passes. But the
     * bootstrappers never run, which means: the tenant is never published to PostgreSQL so
     * row level security matches nothing, the cache prefix is never applied so one workspace
     * can read another's cached values, uploads are never re-rooted, spatie's team key is
     * never set, and queued jobs lose their tenant entirely.
     *
     * In other words the primary isolation layer keeps working while all three backstops are
     * silently absent — the single worst failure mode in this architecture.
     */
    private function registerTenancyLifecycle(): void
    {
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
    }
}
