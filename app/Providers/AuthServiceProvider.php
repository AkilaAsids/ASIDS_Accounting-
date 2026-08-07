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
        /**
         * @param  array<int, mixed>  $arguments
         */
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            // DENY FIRST. A suspended or deactivated account holds no capability at all.
            //
            // This must live in `before`, not `after`. Laravel's after callbacks combine with
            // `$result ??= $afterResult`, so they can only fill in a *null* result — they cannot
            // revoke one that a policy or an earlier `before` already granted. An inactive-account
            // check written as an `after` callback is silently discarded on every check that
            // returned true, which is precisely the checks that matter.
            if (! $user->isActive()) {
                return false;
            }

            // A tenant's owner role holds every capability inside that tenant. Returning true
            // short-circuits the policy; returning null (not false) when the rule does not apply
            // is essential — false would deny outright and make every policy unreachable.
            if ($user->isTenantOwner()) {
                return true;
            }

            // ASIDS platform staff can operate the platform, but this is *not* a licence to read
            // customer books. Tenant-scoped abilities fall through to the ordinary policy, which
            // a platform admin fails because they hold no company membership. Reading a
            // customer's data requires the audited impersonation flow instead.
            if ($user->is_platform_admin && str_starts_with($ability, 'platform.')) {
                return true;
            }

            // The permission check itself, performed here rather than by spatie's own gate hook.
            //
            // That hook is disabled in `config/permission.php` — see the comment there. It
            // registers itself while the Gate is being resolved, so it always sits ahead of this
            // callback, and because Laravel takes the first non-null result it granted permissions
            // *before* the account-status check above could deny them. Doing it here is what makes
            // "suspended holds nothing" true rather than aspirational.
            //
            // `checkPermissionTo` returns false for an unknown permission rather than throwing, and
            // `?: null` converts a false into "no opinion" so a policy still gets its say — an
            // ability may be granted by a policy without existing in the catalogue at all.
            // Unconditional, unlike the package's own version of this: it guards with
            // `method_exists` because its callback receives any `Authorizable`, whereas this closure
            // is typed to `User`, which carries `HasRoles`. The guard would be dead code.
            //
            // Mirrors the package's argument handling: `can('ability', 'guard')` passes the guard
            // positionally, and it must not be mistaken for a policy's model argument.
            $guard = is_string($arguments[0] ?? null) && ! class_exists($arguments[0])
                ? $arguments[0]
                : null;

            return $user->checkPermissionTo($ability, $guard) ?: null;
        });
    }
}
