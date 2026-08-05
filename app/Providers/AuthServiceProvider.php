<?php

declare(strict_types=1);

namespace App\Providers;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Global authorisation rules.
 *
 * Individual policies are registered by the module that owns the model. Only the
 * two rules that must apply *before* any policy live here.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerGlobalGates();
    }

    private function registerGlobalGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            // A tenant's owner role holds every capability inside that tenant.
            // Returning true short-circuits the policy; returning null (not
            // false) when the rule does not apply is essential — false would
            // deny outright and make every policy unreachable.
            if ($user->isTenantOwner()) {
                return true;
            }

            // ASIDS platform staff can operate the platform, but this is *not* a
            // licence to read customer books. Tenant-scoped abilities fall
            // through to the ordinary policy, which the platform admin fails
            // because they hold no company membership. Reading a customer's data
            // requires the audited impersonation flow instead.
            if ($user->is_platform_admin && str_starts_with($ability, 'platform.')) {
                return true;
            }

            return null;
        });

        // A suspended or deactivated account must not pass any check, even one a
        // policy would otherwise allow.
        Gate::after(static function (User $user, string $ability, ?bool $result): ?bool {
            if ($result === true && ! $user->isActive()) {
                return false;
            }

            return $result;
        });
    }
}
