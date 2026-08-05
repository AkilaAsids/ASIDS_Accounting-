<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;

/**
 * Framework-level configuration. Domain wiring lives in the module providers
 * under `src/Core/*/Providers`.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->enforceHttps();
        $this->configureDates();
        $this->configureVite();
        $this->registerQueryMacros();
        $this->configureTestingAids();
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
