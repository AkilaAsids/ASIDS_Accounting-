<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\User;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| This file exists for one reason, and it is not optional: the framework default
| for `providers.users.model` is `App\Models\User`, which this codebase does not
| have. Models live under `Asids\Core\<Module>\Domain\Models`.
|
| Without this override the failure is subtle and severe. Sign-in itself *works* —
| AuthenticationService looks the user up through Eloquent and hands the model to
| `$guard->login()`, so the provider is never asked to resolve anything. It is the
| *next* request that breaks: the session guard calls `retrieveById()`, the
| provider tries to instantiate `App\Models\User`, and every authenticated request
| after sign-in dies with a 500 or bounces to the sign-in screen. Remember-me
| tokens and `Auth::loginUsingId()` fail the same way.
|
| Note that a published config file REPLACES the framework default wholesale —
| config files are not deep merged — so the full structure is reproduced here even
| where the values match the defaults.
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    | One guard. The SPA authenticates with a Sanctum cookie over this session
    | guard; integrations authenticate with bearer tokens, which Sanctum resolves
    | against the same provider. `sanctum.guard` in config/sanctum.php names it.
    */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | The whole point of this file.
    */
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset brokers
    |--------------------------------------------------------------------------
    | Declared so anything resolving `auth.passwords` finds a well-formed entry,
    | but ASIDS does not use Laravel's broker and the `password_reset_tokens`
    | table is deliberately not created: it is keyed on the e-mail address, and an
    | address is unique only *within* a tenant, so a reset requested for one
    | workspace could be redeemed in another. AccountLinkService issues expiring
    | signed links bound to the user's UUID and current credential hash instead.
    */
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password confirmation timeout
    |--------------------------------------------------------------------------
    | Laravel's own re-confirmation window. ASIDS protects sensitive actions with
    | two factor step-up (`config('asids.auth.two_factor.confirmation_ttl')`)
    | rather than a password re-prompt, so this is only a backstop.
    */
    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),
];
