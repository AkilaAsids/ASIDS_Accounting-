<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap — test-environment safety guard.
 *
 * The application runs inside a container whose *real* environment carries the
 * development DB_DATABASE / CACHE_STORE (loaded from the docker `env_file`). A
 * real environment variable takes precedence over PHPUnit's `<php><env>` block
 * — even with `force="true"` — so without this guard `php artisan test` resolves
 * DB_DATABASE to the DEV database and RefreshDatabase's `migrate:fresh` wipes it.
 *
 * We force the test-only values here, before the framework reads the
 * environment, using putenv()/$_ENV/$_SERVER so Laravel's Env repository sees
 * them. The override is conditional: it only fires when the leaked value is
 * clearly not a test value, so CI — and parallel testing, whose per-token
 * databases still contain "testing" — is left untouched.
 */
$forceTestEnv = static function (string $key, string $value, callable $looksWrong): void {
    $current = getenv($key);

    if ($current === false || $current === '' || $looksWrong((string) $current)) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
};

// A test database name must contain "testing"; anything else is the dev DB leaking in.
$forceTestEnv('DB_DATABASE', 'asids_erp_testing', static fn (string $v): bool => ! str_contains($v, 'testing'));

// The cache must be the per-process array store; a shared Redis leaks tenant/rate-limiter state.
$forceTestEnv('CACHE_STORE', 'array', static fn (string $v): bool => $v !== 'array');

require __DIR__.'/../vendor/autoload.php';
