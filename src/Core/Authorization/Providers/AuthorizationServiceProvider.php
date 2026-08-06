<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Providers;

use Asids\Core\Authorization\Domain\Models\Permission;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Authorization\Policies\PermissionPolicy;
use Asids\Core\Authorization\Policies\RolePolicy;
use Asids\Core\Authorization\Presentation\Console\SyncPermissionsCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }
    }
}
