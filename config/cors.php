<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Credentials are included on cross-origin requests (the SPA authenticates with
| a Sanctum cookie), which means `allowed_origins` can never be "*" — the
| browser would reject it, and permitting it would be a CSRF hole. Origins are
| therefore enumerated explicitly, with one wildcard *pattern* for tenant
| subdomains of the configured central domain.
|
*/

$centralDomain = (string) env('TENANCY_CENTRAL_DOMAIN', 'localhost');

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'ops/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        implode(',', array_filter([
            env('APP_URL'),
            env('APP_ENV') === 'local' ? 'http://localhost:5173' : null,
        ]))
    ))))),

    'allowed_origins_patterns' => [
        // https://acme.erp.asidstech.com and http(s) localhost equivalents.
        '#^https?://[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.'.preg_quote($centralDomain, '#').'(?::\d+)?$#i',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Tenant',
        'X-Company',
        'X-Request-Id',
        'X-Idempotency-Key',
    ],

    'exposed_headers' => [
        'X-Request-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 3600,

    'supports_credentials' => true,
];
