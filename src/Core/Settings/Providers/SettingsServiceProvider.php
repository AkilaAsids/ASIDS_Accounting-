<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Providers;

use Asids\Core\Identity\Domain\Events\UserDeactivated;
use Asids\Core\Organization\Domain\Events\CompanyArchived;
use Asids\Core\Settings\Application\Services\SettingsResolver;
use Asids\Core\Settings\Application\Services\SettingsService;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Asids\Core\Settings\Policies\SettingPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the resolver memoises per request, and a queue worker handling
        // successive jobs for different tenants must not inherit the first one's overrides.
        $this->app->scoped(SettingsResolver::class);
        $this->app->scoped(SettingsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(Setting::class, SettingPolicy::class);

        $this->purgeOrphanedOverrides();
    }

    /**
     * `settings.scope_id` intentionally has no foreign key — it points at a user or a company
     * depending on the row's scope, and no single constraint can express that. Cleanup is therefore
     * the owning module's responsibility, which these two listeners discharge. Without them a
     * deactivated user's personal overrides accumulate forever, and a company's overrides would be
     * inherited by a future company that happened to reuse the id (which cannot happen with UUIDs,
     * but the rows would still be dead weight).
     */
    private function purgeOrphanedOverrides(): void
    {
        Event::listen(UserDeactivated::class, function (UserDeactivated $event): void {
            $this->app->make(SettingsService::class)->purgeScope(
                SettingScope::User,
                (string) $event->user->getKey(),
            );
        });

        Event::listen(CompanyArchived::class, function (CompanyArchived $event): void {
            $this->app->make(SettingsService::class)->purgeScope(
                SettingScope::Company,
                (string) $event->company->getKey(),
            );
        });
    }
}
