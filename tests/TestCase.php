<?php

declare(strict_types=1);

namespace Tests;

use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InteractsWithTenants;
use Throwable;

/**
 * Base test case.
 *
 * Two things happen here that are easy to get wrong and expensive to debug:
 *
 *  1. **The permission catalogue is synchronised once per test.** Roles reference permission
 *     rows, so a test that provisions a workspace against an empty `permissions` table
 *     produces roles that grant nothing — and then fails with "403" instead of "you forgot to
 *     seed". Running the real synchroniser rather than inserting rows also means the catalogue
 *     under test is the one the application ships.
 *
 *  2. **Setup runs with row level security suspended.** Factories create rows across several
 *     tenants before any tenant context exists, which the policies would otherwise refuse.
 *     Tests that need to observe RLS enforce it explicitly — see RowLevelSecurityTest.
 */
abstract class TestCase extends BaseTestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force the in-memory cache store for every test. RefreshDatabase rolls the
        // database back between tests but never the cache, so with a persistent store
        // (Redis, shared with the dev container here) a tenant slug cached by one test
        // resolves later tests to a rolled-back tenant id and every RLS-scoped lookup
        // 404s. The `array` store is per-process and cannot leak across the rollback.
        // Set here rather than in phpunit.xml because the container's real CACHE_STORE
        // env var wins over PHPUnit's <env>, even with force="true".
        config(['cache.default' => 'array']);

        RowLevelSecurity::bypass(function (): void {
            app(PermissionSynchroniser::class)->sync();
        });
    }

    protected function tearDown(): void
    {
        // Tenancy state is static on the container; a leaked tenant would make the next test's
        // scope resolve against the previous one's workspace.
        //
        // Guarded because a test that deliberately provokes a database error — an RLS refusal, a
        // check constraint — leaves the transaction aborted, and any statement here would throw
        // a second exception that both masks the first and prevents RefreshDatabase from rolling
        // back. The un-rolled-back transaction then holds locks that block the *next* test
        // indefinitely, which presents as the whole suite hanging rather than as one failure.
        try {
            $this->endTenancy();
        } catch (Throwable) {
            // The rollback below resets session state regardless.
        }

        parent::tearDown();
    }
}
