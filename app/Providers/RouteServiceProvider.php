<?php

declare(strict_types=1);

namespace App\Providers;

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Route model binding and rate limiting.
 *
 * Limits are keyed by *tenant plus principal*, never by IP alone. A Sri Lankan
 * SME's whole office typically shares one NAT address, so an IP-keyed limit would
 * make one busy user throttle their colleagues.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configureBindings();
    }

    private function configureRateLimiters(): void
    {
        /** @var array<string, int> $limits */
        $limits = config('asids.api.rate_limits');

        // General API traffic.
        RateLimiter::for('api', function (Request $request) use ($limits): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute($limits['authenticated'])->by($this->principalKey($request, $user))
                : Limit::perMinute($limits['guest'])->by($this->tenantScopedIp($request));
        });

        // Credential endpoints are limited far more aggressively, and by the
        // submitted identity as well as the source, so neither a single victim nor
        // a single attacker can be attacked or attack freely.
        RateLimiter::for('login', function (Request $request) use ($limits): array {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute($limits['login'])->by('login:ip:'.$this->tenantScopedIp($request)),
                Limit::perMinute($limits['login'])->by('login:email:'.$email),
            ];
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute($limits['two_factor'])
            ->by('2fa:'.($request->user()?->getAuthIdentifier() ?? $this->tenantScopedIp($request))));

        RateLimiter::for('password-reset', function (Request $request) use ($limits): array {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute($limits['password_reset'])->by('pwreset:ip:'.$this->tenantScopedIp($request)),
                // Per hour as well as per minute: a slow drip against one address
                // is the realistic enumeration pattern.
                Limit::perHour($limits['password_reset'] * 4)->by('pwreset:email:'.$email),
            ];
        });

        // Exports are expensive; one per user at a time is the point, not a
        // defence against abuse.
        RateLimiter::for('export', fn (Request $request): Limit => Limit::perMinute($limits['export'])
            ->by('export:'.($request->user()?->getAuthIdentifier() ?? $this->tenantScopedIp($request))));
    }

    /**
     * A limiter key that cannot collide across tenants even when two tenants use
     * the same numeric user identifier space.
     */
    private function principalKey(Request $request, User $user): string
    {
        $token = $request->user()?->currentAccessToken();

        // Token-authenticated integrations get their own bucket so a runaway integration cannot
        // exhaust the interactive users' allowance. Cookie-authenticated requests yield a
        // TransientToken, which has no key — hence the instanceof rather than a null check.
        return implode(':', array_filter([
            'p',
            $user->tenant_id ?? 'platform',
            $user->getKey(),
            $token instanceof PersonalAccessToken ? $token->getKey() : null,
        ]));
    }

    private function tenantScopedIp(Request $request): string
    {
        return implode(':', [
            'ip',
            // `headers->get()` rather than `header()`: the latter is typed as possibly returning
            // an array of values, which cannot be interpolated into a rate-limiter key.
            $request->headers->get((string) config('asids.tenancy.header'), 'central'),
            (string) $request->ip(),
        ]);
    }

    private function configureBindings(): void
    {
        // Every model in the platform is UUID keyed. Rejecting a non-UUID at the
        // routing layer means a malformed id becomes a clean 404 instead of a
        // PostgreSQL "invalid input syntax for type uuid" 500.
        Route::pattern('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }
}
