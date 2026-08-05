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
use Illuminate\Support\ServiceProvider;

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
    }
}
