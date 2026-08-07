<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Laravel Sanctum
|--------------------------------------------------------------------------
|
| Two authentication paths share one guard:
|
|   * The first-party SPA uses stateful cookie authentication over the
|     `statefulApi` middleware, so no bearer token is ever stored in JavaScript
|     and XSS cannot exfiltrate a long-lived credential.
|
|   * Mobile apps and third-party integrations use personal access tokens with
|     explicit abilities and a hard expiry.
|
*/

return [

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        implode(',', array_filter([
            'localhost',
            'localhost:5173',
            '127.0.0.1',
            '127.0.0.1:8000',
            '::1',
            Sanctum::currentApplicationUrlWithPort(),
            // Wildcard for tenant subdomains of the central domain.
            '*.'.env('TENANCY_CENTRAL_DOMAIN', 'localhost'),
        ]))
    )),

    'guard' => ['web'],

    // Personal access tokens expire after one year unless an integration asks
    // for less. `null` would mean "never", which is not acceptable for a system
    // holding financial data.
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 365),

    // A recognisable, greppable prefix lets GitHub secret scanning and our own
    // log scrubbers detect a leaked token immediately.
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'asids_pat_'),

    // Subclassed so tokens carry tenant_id, an audit trail and last-used
    // metadata that the vanilla model does not track.
    'model' => PersonalAccessToken::class,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
