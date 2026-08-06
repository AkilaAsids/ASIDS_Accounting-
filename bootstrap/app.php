<?php

declare(strict_types=1);

use Asids\Core\Audit\Presentation\Http\Middleware\RecordRequestContext;
use Asids\Core\Authorization\Presentation\Http\Middleware\EnsurePasswordIsNotExpired;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureSessionIsCurrent;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureTwoFactorIsConfirmed;
use Asids\Core\Organization\Presentation\Http\Middleware\ResolveActiveCompany;
use Asids\Core\Platform\Exceptions\ApiExceptionRenderer;
use Asids\Core\Platform\Http\Middleware\AssignRequestId;
use Asids\Core\Platform\Http\Middleware\ForceJsonResponse;
use Asids\Core\Tenancy\Presentation\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NOTE: this closure runs when the kernel is resolved, which is *before* the
        // config service is registered. `config()` is therefore unavailable here — it
        // fails with "Target class [config] does not exist" and the application never
        // boots. Trusted proxies are configured in AppServiceProvider::boot() instead,
        // where config is loaded, via TrustProxies' static setters.

        // The SPA authenticates with Sanctum cookies; mobile and third-party
        // integrations authenticate with personal access tokens.
        $middleware->statefulApi();

        $middleware->api(prepend: [
            AssignRequestId::class,
            ForceJsonResponse::class,
            ResolveTenant::class,
        ]);

        $middleware->api(append: [
            // Runs after authentication so it can see the resolved user, and before the
            // response is returned so a revoked session never reaches a controller.
            EnsureSessionIsCurrent::class,
            RecordRequestContext::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'company' => ResolveActiveCompany::class,
            'password.fresh' => EnsurePasswordIsNotExpired::class,
            'tenant' => ResolveTenant::class,
            'two-factor' => EnsureTwoFactorIsConfirmed::class,
        ]);

        // Authorisation is enforced explicitly by policies and permission
        // middleware; nothing is implicitly trusted.
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })
    ->create();
