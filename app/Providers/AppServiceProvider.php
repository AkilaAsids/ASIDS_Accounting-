<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;

/**
 * Framework-level configuration. Domain wiring lives in each module's own service
 * provider under `src/Core`.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureTrustedProxies();
        $this->enforceHttps();
        $this->configureDates();
        $this->configureVite();
        $this->registerQueryMacros();
        $this->configureTestingAids();
    }

    /**
     * Trust the load balancer in front of the application, so HTTPS detection, client IP
     * resolution and rate limiting stay correct.
     *
     * Configured here rather than in `bootstrap/app.php` because that file's middleware
     * closure runs before the config service exists — calling `config()` there fails with
     * "Target class [config] does not exist" and the application never boots. TrustProxies'
     * static setters are the supported way to configure it from a provider.
     */
    private function configureTrustedProxies(): void
    {
        /** @var list<string>|string|null $proxies */
        $proxies = config('asids.trusted_proxies');

        if ($proxies !== null) {
            TrustProxies::at($proxies);
        }

        // The HEADER_* constants live on Symfony's Request, which Illuminate\Http\Request
        // extends — not on TrustProxies itself.
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    }

    /**
     * Signed URLs (document downloads, invitation links) must never be generated
     * with an http scheme behind a TLS-terminating load balancer: the signature
     * covers the URL, so a scheme mismatch invalidates every link.
     */
    private function enforceHttps(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }
    }

    private function configureDates(): void
    {
        // CarbonImmutable removes a whole class of bug where a date is mutated in
        // place by a helper and the caller's value silently changes — which in an
        // accounting system means a transaction landing in the wrong period.
        Date::use(\Carbon\CarbonImmutable::class);
    }

    private function configureVite(): void
    {
        Vite::useAggressivePrefetching();
    }

    /**
     * Small, widely useful query macros. Kept few on purpose: a macro is global
     * surface area, and anything domain specific belongs in a scope on its model.
     */
    private function registerQueryMacros(): void
    {
        // `->whenFilter($criteria, 'status', fn ($q, $v) => ...)` reads better at
        // the call site than a nest of if statements, and keeps the "is this
        // filter present" decision in one place.
        Builder::macro('applyFilter', function (mixed $value, callable $callback): Builder {
            /** @var Builder $this */
            return ($value === null || $value === '') ? $this : $callback($this, $value);
        });
    }

    private function configureTestingAids(): void
    {
        if ($this->app->runningUnitTests()) {
            // Any real sleep in a test is a bug waiting to become a flake.
            Sleep::fake();
        }
    }
}
