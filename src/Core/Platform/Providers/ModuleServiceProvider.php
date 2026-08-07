<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Providers;

use Asids\Core\Accounting\Providers\AccountingServiceProvider;
use Asids\Core\Audit\Providers\AuditServiceProvider;
use Asids\Core\Authorization\Providers\AuthorizationServiceProvider;
use Asids\Core\Identity\Providers\IdentityServiceProvider;
use Asids\Core\Organization\Providers\OrganizationServiceProvider;
use Asids\Core\Settings\Providers\SettingsServiceProvider;
use Asids\Core\Tenancy\Providers\TenancyServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Single entry point for the modular monolith.
 *
 * Modules are registered in dependency order and are the *only* place a module's
 * internals are wired. Adding a bounded context to the platform therefore means
 * adding one line here, and nothing in `bootstrap/providers.php` changes.
 *
 * Order matters: Tenancy installs the connection-level tenant context that every
 * other module's global scopes depend upon, Authorization must be able to see
 * the Identity models it grants roles to, and Accounting derives its fiscal
 * calendar from the companies Organization defines.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<ServiceProvider>>
     */
    private const array MODULES = [
        PlatformServiceProvider::class,
        TenancyServiceProvider::class,
        IdentityServiceProvider::class,
        AuthorizationServiceProvider::class,
        OrganizationServiceProvider::class,
        // After Organization: a company must exist before it can keep books, and the fiscal
        // calendar is derived from the company's own configured year start.
        AccountingServiceProvider::class,
        SettingsServiceProvider::class,
        AuditServiceProvider::class,
    ];

    public function register(): void
    {
        foreach (self::MODULES as $module) {
            $this->app->register($module);
        }
    }
}
