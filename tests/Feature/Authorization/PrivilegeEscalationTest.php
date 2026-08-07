<?php

declare(strict_types=1);

use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Application\Services\RoleService;
use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Authorization\Domain\Exceptions\LastOwnerCannotBeRemoved;
use Asids\Core\Authorization\Domain\Exceptions\PermissionNotGrantable;
use Asids\Core\Authorization\Domain\Exceptions\RoleNotGrantable;
use Asids\Core\Authorization\Domain\Exceptions\SystemRoleIsProtected;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Privilege escalation refusal.
 *
 * Permissions alone cannot express "an administrator must not be able to mint another owner" —
 * that is what role levels are for, and a total order is only as good as the checks that
 * consult it. These tests cover every path by which someone could acquire authority they were
 * not given.
 */
beforeEach(function (): void {
    $workspace = $this->createWorkspace('acme');

    $this->tenant = $workspace['tenant'];
    $this->owner = $workspace['owner'];
    $this->roles = $workspace['roles'];

    $this->administrator = $this->createUserWithRole($this->tenant, 'administrator');
    $this->bookkeeper = $this->createUserWithRole($this->tenant, 'bookkeeper');
});

describe('role assignment', function (): void {
    it('refuses to assign the owner role', function (): void {
        $this->withinTenant($this->tenant);

        $exception = catchPlatformException(fn () => app(RoleService::class)->assign(
            $this->bookkeeper,
            [$this->roles['owner']->getKey()],
            $this->owner,
        ));

        // Even the owner cannot hand out ownership as an ordinary assignment — it goes through
        // the explicit, audited, step-up-protected transfer instead.
        expect($exception)->toBeInstanceOf(RoleNotGrantable::class)
            ->and($exception->problemCode())->toBe('owner-role-not-assignable');
    });

    it('refuses to assign a role at or above the actor’s own level', function (): void {
        $this->withinTenant($this->tenant);

        // Administrator (90) attempting to grant Administrator (90). Strictly-below, not
        // below-or-equal: two administrators must not be able to clone each other.
        $exception = catchPlatformException(fn () => app(RoleService::class)->assign(
            $this->bookkeeper,
            [$this->roles['administrator']->getKey()],
            $this->administrator,
        ));

        expect($exception)->toBeInstanceOf(RoleNotGrantable::class)
            ->and($exception->problemCode())->toBe('role-not-grantable');
    });

    it('permits assigning a role below the actor’s level', function (): void {
        $this->withinTenant($this->tenant);

        $updated = app(RoleService::class)->assign(
            $this->bookkeeper,
            [$this->roles['viewer']->getKey()],
            $this->administrator,
        );

        expect($updated->tenantRoles()->pluck('name')->all())->toBe(['viewer']);
    });

    it('refuses an unknown role id rather than silently granting less', function (): void {
        $this->withinTenant($this->tenant);

        // Silently dropping an unresolvable id would mean the caller believed they granted
        // something and did not — a divergence nobody notices until an audit.
        expect(fn () => app(RoleService::class)->assign(
            $this->bookkeeper,
            [(string) Str::uuid7()],
            $this->owner,
        ))->toThrow(RoleNotGrantable::class);
    });

    it('refuses to remove the workspace’s last owner', function (): void {
        $this->withinTenant($this->tenant);

        expect(fn () => app(RoleService::class)->assign(
            $this->owner,
            [$this->roles['viewer']->getKey()],
            $this->owner,
        ))->toThrow(LastOwnerCannotBeRemoved::class);
    });

    it('permits removing an owner once another exists', function (): void {
        $this->withinTenant($this->tenant);

        $second = $this->createUserWithRole($this->tenant, 'administrator');
        app(RoleService::class)->transferOwnership(from: $this->owner, to: $second, actor: $this->owner);

        // The former owner can now be demoted, because the workspace retains one.
        $demoted = app(RoleService::class)->assign(
            $this->owner,
            [$this->roles['viewer']->getKey()],
            $second,
        );

        expect($demoted->fresh()->isTenantOwner())->toBeFalse()
            ->and($second->fresh()->isTenantOwner())->toBeTrue();
    });
});

describe('role definition', function (): void {
    it('clamps a new role’s level to strictly below its creator’s', function (): void {
        $this->withinTenant($this->tenant);

        // Requesting 99 as an administrator (90). Creating a role must never be a route to
        // authority the creator does not hold.
        $role = app(RoleService::class)->create(
            new RoleData(label: 'Senior Reviewer', description: null, permissionNames: [], level: 99),
            $this->administrator,
        );

        expect($role->level)->toBeLessThan($this->administrator->highestRoleLevel());
    });

    it('refuses to grant a platform-staff capability to a workspace role', function (): void {
        $this->withinTenant($this->tenant);

        $exception = catchPlatformException(fn () => app(RoleService::class)->create(
            new RoleData(
                label: 'Sneaky',
                description: null,
                permissionNames: ['platform.tenants.suspend'],
            ),
            $this->owner,
        ));

        expect($exception)->toBeInstanceOf(PermissionNotGrantable::class);
    });

    it('refuses to rename a built-in role', function (): void {
        $this->withinTenant($this->tenant);

        // Provisioning, the seeders and customer integrations all refer to `administrator` by
        // name, so its identity is fixed even though its permissions are not.
        expect(fn () => app(RoleService::class)->update(
            $this->roles['administrator'],
            new RoleData(label: 'Renamed', description: null, permissionNames: []),
            $this->owner,
        ))->toThrow(SystemRoleIsProtected::class);
    });

    it('permits changing a built-in role’s permissions', function (): void {
        $this->withinTenant($this->tenant);

        $updated = app(RoleService::class)->update(
            $this->roles['accountant'],
            new RoleData(
                label: $this->roles['accountant']->label,
                description: 'Adjusted',
                permissionNames: ['organization.companies.view'],
            ),
            $this->owner,
        );

        expect($updated->description)->toBe('Adjusted');
    });
});

describe('the owner short circuit', function (): void {
    it('grants the owner every capability without pivot rows', function (): void {
        $this->withinTenant($this->tenant);
        $this->actingAs($this->owner);

        // Ownership is implicit via Gate::before. An exhaustive pivot set would mean a
        // capability added in a later phase is silently missing from the paying customer's role.
        expect($this->owner->can('audit.logs.export'))->toBeTrue()
            ->and($this->roles['owner']->permissions()->count())->toBe(0);
    });

    it('does not grant a suspended owner anything', function (): void {
        $this->withinTenant($this->tenant);

        $this->owner->status = UserStatus::Suspended;
        $this->owner->save();
        $this->actingAs($this->owner);

        // The deny-first branch of `Gate::before` runs ahead of the owner short circuit. Without
        // that ordering, suspension would not take effect for the one account that matters most.
        expect($this->owner->can('audit.logs.export'))->toBeFalse();
    });

    it('does not grant a suspended user the permissions their roles carry', function (): void {
        $this->withinTenant($this->tenant);

        $administrator = $this->createUserWithRole($this->tenant, 'administrator');

        expect($administrator->can('identity.users.view'))->toBeTrue();

        $administrator->status = UserStatus::Suspended;
        $administrator->save();
        $administrator->forgetAuthorizationState();

        // REGRESSION. This is not the same test as the one above, and it failed while that one
        // passed. The owner is granted by our own `Gate::before` callback, so the deny above it was
        // reached; an ordinary user's permissions were granted by *spatie's* callback, which
        // registers itself during Gate resolution and therefore always sat at index 0 — ahead of
        // any deny we could append. Laravel takes the first non-null result, so suspension revoked
        // nothing for anyone whose authority came from a role.
        //
        // Which is every user except the owner. A suspended employee kept every capability their
        // roles granted, and so did any personal access token they had issued.
        //
        // Fixed by turning off `permission.register_permission_check_method` and performing the
        // permission check inside our own callback, after the account-status check.
        expect($administrator->can('identity.users.view'))->toBeFalse();

        // They still *hold* the role — suspension removes authority, not membership, so reinstating
        // them restores exactly what they had.
        expect($administrator->hasPermissionTo('identity.users.view'))->toBeTrue();
    });

    it('does not grant a deactivated user the permissions their roles carry', function (): void {
        $this->withinTenant($this->tenant);

        $administrator = $this->createUserWithRole($this->tenant, 'administrator');

        $administrator->status = UserStatus::Deactivated;
        $administrator->save();
        $administrator->forgetAuthorizationState();

        expect($administrator->can('identity.users.view'))->toBeFalse();
    });

    it('registers exactly one gate before callback, so ordering cannot be reintroduced', function (): void {
        $gate = Gate::getFacadeRoot();

        $callbacks = (new ReflectionProperty($gate, 'beforeCallbacks'))->getValue($gate);

        // Asserted structurally, because the bug was invisible in behaviour for the owner — the one
        // account the original test happened to use. If a future change re-enables the package's
        // gate hook, or an upgrade changes its default back, this fails immediately and names the
        // reason rather than leaving suspension quietly ineffective.
        expect($callbacks)->toHaveCount(1);

        $registered = new ReflectionFunction($callbacks[0]);

        expect($registered->getFileName())->toEndWith('app/Providers/AuthServiceProvider.php');
    });

    it('does not let platform staff read customer books by virtue of being staff', function (): void {
        $staff = RowLevelSecurity::bypass(
            fn () => User::factory()->platformAdmin()->create(),
        );

        $this->withinTenant($this->tenant);
        $this->actingAs($staff);

        // `platform.*` is short-circuited for staff; tenant-scoped abilities are not, and they
        // hold no company membership. Reading customer data requires the audited impersonation
        // flow instead.
        expect($staff->can('platform.tenants.view'))->toBeTrue()
            ->and($staff->canAccessCompany((string) Str::uuid7()))->toBeFalse();
    });
});

describe('the permission catalogue', function (): void {
    it('never offers a platform capability as tenant-grantable', function (): void {
        $grantable = PermissionCatalogue::tenantGrantableNames();

        expect(array_filter($grantable, fn (string $n) => str_starts_with($n, 'platform.')))->toBeEmpty();
    });

    it('composes every permission name from its own parts', function (): void {
        // The `permissions` table has a CHECK asserting exactly this. Testing it here means a
        // catalogue mistake fails in a readable way rather than as a constraint violation
        // during deployment.
        foreach (PermissionCatalogue::all() as $definition) {
            expect($definition->name())
                ->toBe("{$definition->module}.{$definition->resource}.{$definition->action}");
        }
    });

    it('gives the owner template every grantable capability', function (): void {
        $template = RoleTemplate::owner();

        expect($template->resolvedPermissions())
            ->toEqualCanonicalizing(PermissionCatalogue::tenantGrantableNames());
    });
});
