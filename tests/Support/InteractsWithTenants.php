<?php

declare(strict_types=1);

namespace Tests\Support;

use Asids\Core\Authorization\Application\Services\RoleProvisioner;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Closure;

/**
 * Workspace construction for tests.
 *
 * Deliberately builds through the real services — `RoleProvisioner`, `CompanyService` — rather
 * than inserting rows. A helper that fabricates a workspace by hand tests a world the
 * application never produces, and the first bug it misses is the one where provisioning and
 * the rest of the system disagree.
 */
trait InteractsWithTenants
{
    /**
     * A workspace with system roles, an owner, and a company the owner is a member of.
     *
     * @return array{tenant: Tenant, owner: User, company: Company, roles: array<string, Role>}
     */
    protected function createWorkspace(string $slug = 'acme', array $tenantAttributes = []): array
    {
        return RowLevelSecurity::bypass(function () use ($slug, $tenantAttributes): array {
            /** @var Tenant $tenant */
            $tenant = Tenant::factory()->create(['slug' => $slug, ...$tenantAttributes]);

            $ownerRole = app(RoleProvisioner::class)->provisionSystemRolesFor($tenant);

            return app(TenantContext::class)->runFor($tenant, function () use ($tenant, $ownerRole): array {
                /** @var User $owner */
                $owner = User::factory()->create(['tenant_id' => $tenant->getKey()]);
                $this->giveRole($owner, $ownerRole);

                $company = app(CompanyService::class)->create(
                    new CreateCompanyData(name: $tenant->name, isDefault: true),
                    $owner,
                );

                /** @var array<string, Role> $roles */
                $roles = Role::query()->assignable()->get()->keyBy('name')->all();

                return ['tenant' => $tenant, 'owner' => $owner, 'company' => $company, 'roles' => $roles];
            });
        });
    }

    /**
     * A user in the given workspace holding a named system role.
     */
    protected function createUserWithRole(Tenant $tenant, string $roleName, array $attributes = []): User
    {
        return RowLevelSecurity::bypass(fn (): User => app(TenantContext::class)->runFor(
            $tenant,
            function () use ($tenant, $roleName, $attributes): User {
                /** @var User $user */
                $user = User::factory()->create(['tenant_id' => $tenant->getKey(), ...$attributes]);

                $role = Role::query()->assignable()->where('name', $roleName)->firstOrFail();
                $this->giveRole($user, $role);

                return $user;
            },
        ));
    }

    /**
     * Runs a closure inside a workspace, as a given user.
     *
     * Both context switches matter: the Eloquent scope needs the tenant, and the authorisation
     * gate needs the user. A test that sets only one produces confusing results — usually an
     * empty result set that looks like a query bug.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function asUserIn(Tenant $tenant, User $user, Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($tenant, function () use ($user, $callback): mixed {
            $this->actingAs($user);

            return $callback();
        });
    }

    /**
     * Establishes tenant context for the remainder of the test.
     */
    protected function withinTenant(Tenant $tenant): void
    {
        app(TenantContext::class)->initialize($tenant);
    }

    protected function endTenancy(): void
    {
        app(TenantContext::class)->end();
    }

    /**
     * Assigns a role via the pivot directly.
     *
     * RoleService is bypassed on purpose: it enforces "strictly below the actor's level", which
     * has no meaning when constructing a fixture with no actor. Those rules are tested
     * explicitly in PrivilegeEscalationTest.
     */
    private function giveRole(User $user, Role $role): void
    {
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $role->getKey(),
            'model_type' => 'user',
            'model_uuid' => $user->getKey(),
            'tenant_id' => $user->tenant_id,
        ]);

        $user->forgetAuthorizationState();
    }
}
