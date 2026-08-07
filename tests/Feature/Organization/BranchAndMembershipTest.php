<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\BranchService;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;

/**
 * Branches and company memberships.
 *
 * Two invariants carry the weight. A company must always have exactly one active primary branch,
 * because that is where a transaction is recorded when no branch is named — a company with none has
 * documents that cannot be posted, and one with two has documents that post to whichever row the
 * database returns first. And a membership must be reinstated rather than duplicated, because the
 * unique index covers revoked rows and a second insert fails at the database.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->branches = app(BranchService::class);
    $this->memberships = app(MembershipService::class);
    $this->companies = app(CompanyService::class);
});

describe('the primary branch invariant', function (): void {
    it('gives a new company exactly one active primary branch', function (): void {
        expect(Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->count())
            ->toBe(1);
    });

    it('creates additional branches as non-primary', function (): void {
        $branch = $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        expect($branch->is_primary)->toBeFalse()
            ->and(Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->count())
            ->toBe(1);
    });

    it('moves the primary designation without ever leaving two or none', function (): void {
        $kandy = $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        $this->branches->makePrimary($kandy);

        // The partial unique index would reject a second primary, and clearing the old one first
        // would leave a window with nowhere to post. Both writes are in one transaction.
        $primaries = Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->get();

        expect($primaries)->toHaveCount(1)
            ->and($primaries->first()?->getKey())->toBe($kandy->getKey());
    });

    it('is a no-op when the branch is already primary', function (): void {
        $primary = Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->firstOrFail();

        expect($this->branches->makePrimary($primary)->getKey())->toBe($primary->getKey())
            ->and(Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->count())
            ->toBe(1);
    });

    it('refuses to archive the primary branch', function (): void {
        $primary = Branch::query()->forCompany($this->company->getKey())->where('is_primary', true)->firstOrFail();

        $exception = catchPlatformException(fn () => $this->branches->archive($primary));

        expect($exception->problemCode())->toBe('cannot-archive-primary-branch');
    });

    it('refuses to make an archived branch primary', function (): void {
        $kandy = $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);
        $this->branches->archive($kandy);

        $exception = catchPlatformException(fn () => $this->branches->makePrimary($kandy));

        // The table's check constraint says a primary branch must be active. Reaching it would
        // surface as a constraint name rather than a sentence.
        expect($exception->problemCode())->toBe('archived-branch-cannot-be-primary');
    });
});

describe('branch codes', function (): void {
    it('refuses a duplicate code within the same company', function (): void {
        $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        $exception = catchPlatformException(
            fn () => $this->branches->create($this->company, ['name' => 'Kandy Two', 'code' => 'KDY']),
        );

        expect($exception->problemCode())->toBe('duplicate-resource');
    });

    it('refuses a duplicate code differing only in case', function (): void {
        $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        // The uniqueness is an expression index on `lower(code)`: branch codes appear on document
        // numbers, where KDY and kdy are the same branch to every human reading them.
        $exception = catchPlatformException(
            fn () => $this->branches->create($this->company, ['name' => 'Kandy Two', 'code' => 'kdy']),
        );

        expect($exception->problemCode())->toBe('duplicate-resource');
    });

    it('permits the same code in a different company', function (): void {
        $other = $this->companies->create(new CreateCompanyData(name: 'Other Entity'), $this->owner);

        $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);
        $second = $this->branches->create($other, ['name' => 'Kandy', 'code' => 'KDY']);

        // Scoped per company, not per workspace: two legal entities may both have a Kandy branch.
        expect($second->code)->toBe('KDY');
    });

    it('refuses an archived company any new branches', function (): void {
        $other = $this->companies->create(new CreateCompanyData(name: 'Closing'), $this->owner);
        $this->companies->archive($other, $this->owner);

        $exception = catchPlatformException(
            fn () => $this->branches->create($other->refresh(), ['name' => 'Too Late', 'code' => 'TL']),
        );

        expect($exception->problemCode())->toBe('archived-company-cannot-gain-branches');
    });
});

describe('granting access', function (): void {
    it('grants a membership', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');

        $membership = $this->memberships->grant($this->company, $user, $this->owner);

        expect($membership->isActive())->toBeTrue()
            ->and($membership->company_id)->toBe($this->company->getKey())
            ->and($membership->granted_by_id)->toBe($this->owner->getKey());
    });

    it('reinstates a revoked membership rather than duplicating it', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');

        $first = $this->memberships->grant($this->company, $user, $this->owner);
        $this->memberships->revoke($first, $this->owner);

        $second = $this->memberships->grant($this->company, $user, $this->owner);

        // The unique index on (company_id, user_id) covers revoked rows, so a second insert fails
        // at the database. Reinstating also keeps `joined_at`, which is the honest history.
        expect($second->getKey())->toBe($first->getKey())
            ->and($second->revoked_at)->toBeNull()
            ->and(CompanyMembership::query()
                ->where('company_id', $this->company->getKey())
                ->where('user_id', $user->getKey())
                ->count())->toBe(1);
    });

    it('refuses to give platform staff direct access to customer books', function (): void {
        // Built as platform staff from the start, not converted. `users_tenant_or_platform_check`
        // asserts `(tenant_id IS NULL) = is_platform_admin`, and the tenant-column write guard
        // refuses to move an existing row between workspaces — so promoting a tenant user to staff
        // is impossible by construction, which is the intended behaviour.
        $staff = platformStaff();

        $exception = catchPlatformException(
            fn () => $this->memberships->grant($this->company, $staff, $this->owner),
        );

        // Staff reach customer data through the audited impersonation flow. A membership would
        // bypass that silently and permanently.
        expect($exception->problemCode())->toBe('platform-staff-membership');
    });

    it('refuses a branch belonging to a different company', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $other = $this->companies->create(new CreateCompanyData(name: 'Elsewhere'), $this->owner);
        $foreignBranch = Branch::query()->forCompany($other->getKey())->firstOrFail();

        $exception = catchPlatformException(
            fn () => $this->memberships->grant($this->company, $user, $this->owner, $foreignBranch),
        );

        expect($exception->problemCode())->toBe('branch-company-mismatch');
    });

    it('refuses access to an archived company', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $other = $this->companies->create(new CreateCompanyData(name: 'Shut'), $this->owner);
        $this->companies->archive($other, $this->owner);

        $exception = catchPlatformException(
            fn () => $this->memberships->grant($other->refresh(), $user, $this->owner),
        );

        expect($exception->problemCode())->toBe('archived-company-access');
    });
});

describe('the default company', function (): void {
    it('promotes a replacement default when the default membership is revoked', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $second = $this->companies->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $primary = $this->memberships->grant($this->company, $user, $this->owner, makeDefault: true);
        $this->memberships->grant($second, $user, $this->owner);

        $this->memberships->revoke($primary, $this->owner);

        // Without promotion the user signs in with no company selected and an empty shell, having
        // done nothing but lose access to one of two companies.
        $remaining = CompanyMembership::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->get();

        expect($remaining)->toHaveCount(1)
            ->and($remaining->first()?->is_default)->toBeTrue();
    });

    it('leaves no default when the last membership is revoked', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $only = $this->memberships->grant($this->company, $user, $this->owner, makeDefault: true);

        $this->memberships->revoke($only, $this->owner);

        // There is nothing to promote, and the check constraint forbids a revoked row from being
        // the default — so the flag has to be cleared rather than left dangling.
        expect(CompanyMembership::query()->active()->where('user_id', $user->getKey())->count())->toBe(0)
            ->and($only->refresh()->is_default)->toBeFalse();
    });

    it('moves the default and records it on the user', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $second = $this->companies->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $this->memberships->grant($this->company, $user, $this->owner, makeDefault: true);
        $this->memberships->grant($second, $user, $this->owner);

        $this->memberships->setDefault($user, $second);

        expect(CompanyMembership::query()->active()->where('user_id', $user->getKey())->where('is_default', true)->count())
            ->toBe(1)
            ->and($user->refresh()->default_company_id)->toBe($second->getKey());
    });

    it('refuses to default to a company the user cannot reach', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $unreachable = $this->companies->create(new CreateCompanyData(name: 'Not Mine'), $this->owner);

        $exception = catchPlatformException(fn () => $this->memberships->setDefault($user, $unreachable));

        expect($exception->problemCode())->toBe('not-a-member');
    });

    it('revoking is idempotent', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $membership = $this->memberships->grant($this->company, $user, $this->owner);

        $this->memberships->revoke($membership, $this->owner);
        $firstRevokedAt = $membership->refresh()->revoked_at;

        $this->memberships->revoke($membership, $this->owner);

        // A second revoke must not move the timestamp: the audit trail would then show the
        // access ending later than it did.
        expect($membership->refresh()->revoked_at?->toIso8601String())
            ->toBe($firstRevokedAt?->toIso8601String());
    });
});

describe('company access', function (): void {
    it('reports only the companies a user is an active member of', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $second = $this->companies->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $third = $this->companies->create(new CreateCompanyData(name: 'Third Books'), $this->owner);

        $this->memberships->grant($this->company, $user, $this->owner);
        $granted = $this->memberships->grant($second, $user, $this->owner);
        $this->memberships->revoke($granted, $this->owner);

        $accessible = $user->accessibleCompanyIds()->all();

        // Membership is data access, separate from and additional to permissions: a bookkeeper with
        // every accounting permission still sees only the companies they belong to.
        expect($accessible)->toContain($this->company->getKey())
            ->and($accessible)->not->toContain($second->getKey())
            ->and($accessible)->not->toContain($third->getKey())
            ->and($user->canAccessCompany($this->company->getKey()))->toBeTrue()
            ->and($user->canAccessCompany($third->getKey()))->toBeFalse();
    });

    it('archiving a company removes it from every member’s access', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
        $second = $this->companies->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $this->memberships->grant($second, $user, $this->owner);

        expect($user->accessibleCompanyIds()->all())->toContain($second->getKey());

        $this->companies->archive($second, $this->owner);

        expect($user->accessibleCompanyIds()->all())->not->toContain($second->getKey());
    });

    it('does not give platform staff any company access', function (): void {
        $staff = platformStaff();

        // Staff deliberately hold no memberships, so the switcher is empty and every company-scoped
        // read is refused. Their route into customer data is the audited impersonation flow.
        expect($staff->accessibleCompanyIds()->all())->toBe([]);
    });
});

describe('branch limits', function (): void {
    it('refuses to exceed the per-company branch limit', function (): void {
        config(['asids.limits.max_branches_per_company' => 2]);

        // One primary branch already exists, so the second is the last permitted.
        $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        $exception = catchPlatformException(
            fn () => $this->branches->create($this->company, ['name' => 'Galle', 'code' => 'GLL']),
        );

        expect($exception->problemCode())->toBe('branch-limit-reached');
    });

    it('counts only active branches against the limit', function (): void {
        config(['asids.limits.max_branches_per_company' => 2]);

        $kandy = $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);
        $this->branches->archive($kandy);

        expect($this->branches->create($this->company, ['name' => 'Galle', 'code' => 'GLL'])->exists)
            ->toBeTrue();
    });

    it('archives and restores a non-primary branch', function (): void {
        $kandy = $this->branches->create($this->company, ['name' => 'Kandy', 'code' => 'KDY']);

        $archived = $this->branches->archive($kandy);
        expect($archived->status)->toBe(OrganizationStatus::Archived)
            ->and($archived->archived_at)->not->toBeNull();

        $restored = $this->branches->restore($archived);
        expect($restored->status)->toBe(OrganizationStatus::Active)
            ->and($restored->archived_at)->toBeNull();
    });
});

/**
 * ASIDS staff: no workspace, `is_platform_admin` true.
 *
 * Created with tenancy suspended, not merely with RLS bypassed. `BelongsToTenant` stamps the active
 * workspace onto any new row, and `users_tenant_or_platform_check` asserts
 * `(tenant_id IS NULL) = is_platform_admin` — so creating staff while a workspace is active fails at
 * the database and, worse, aborts the surrounding transaction so the real assertion never runs.
 */
function platformStaff(): User
{
    return app(TenantContext::class)->runCentrally(
        fn (): User => RowLevelSecurity::bypass(fn (): User => User::factory()->platformAdmin()->create()),
    );
}
