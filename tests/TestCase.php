<?php

declare(strict_types=1);

namespace Tests;

use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InteractsWithTenants;

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

        RowLevelSecurity::bypass(function (): void {
            app(PermissionSynchroniser::class)->sync();
        });
    }

    protected function tearDown(): void
    {
        // Tenancy state is static on the container; a leaked tenant would make the next test's
        // scope resolve against the previous one's workspace.
        $this->endTenancy();

        parent::tearDown();
    }
}
