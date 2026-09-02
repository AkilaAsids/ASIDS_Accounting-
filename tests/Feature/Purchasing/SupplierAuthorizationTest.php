<?php

declare(strict_types=1);

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Infrastructure\EloquentPayableBalanceProbe;
use Asids\Core\Purchasing\Policies\SupplierPolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and change suppliers — Stage 5 of Wave 6 (ADR 0018 §D, §G).
 *
 * The payable-side mirror of `TaxCodeAuthorizationTest` and the authorization block of `CustomerTest`.
 * Two questions are tested and they are genuinely separate: does the role hold the capability, and is
 * the user a member of the company whose records they are touching. Both must be true, and a test that
 * only checked the first would pass for a user with no business in those books at all.
 *
 * `purchasing.suppliers.manage` is `sensitive: true` (the customer `manage` is not) — deciding who you
 * pay is a sensitive action (Gate 2 decision 1, ADR 0018 §D1 / §H item 3).
 *
 * RED expectation before Stage 5 lands: the `purchasing.suppliers.*` permissions, the role grants, and
 * `SupplierPolicy` do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers'));
});

/**
 * A member of the acme company holding the given role. Named distinctly because Pest helpers are global.
 */
function supplierMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares both supplier capabilities', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('purchasing.suppliers.view')
            ->and($names)->toContain('purchasing.suppliers.manage');
    });

    it('marks management sensitive but viewing not', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // Gate 2 decision 1: deciding who you pay is sensitive. `payment_terms_days` and the
        // compliance-bearing TIN ride on `manage`.
        expect($definitions['purchasing.suppliers.manage']->sensitive)->toBeTrue()
            ->and($definitions['purchasing.suppliers.view']->sensitive)->toBeFalse();
    });

    it('orders view before manage within the new purchasing group', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // sortOrder restarts at 10/20 within the group, matching every other group's local numbering.
        expect($definitions['purchasing.suppliers.view']->sortOrder)->toBe(10)
            ->and($definitions['purchasing.suppliers.manage']->sortOrder)->toBe(20);
    });
});

describe('role grants', function (): void {
    it('gives the accountant both capabilities', function (): void {
        $accountant = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'accountant',
        );

        expect($accountant->permissions)->toContain('purchasing.suppliers.view')
            ->and($accountant->permissions)->toContain('purchasing.suppliers.manage');
    });

    it('gives the bookkeeper both capabilities', function (): void {
        // Mirrors the customer grant: entering day-to-day purchases means creating the supplier you are
        // buying from, so the bookkeeper maintains them too (ADR 0018 §D2).
        $bookkeeper = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'bookkeeper',
        );

        expect($bookkeeper->permissions)->toContain('purchasing.suppliers.view')
            ->and($bookkeeper->permissions)->toContain('purchasing.suppliers.manage');
    });

    it('gives the viewer view only', function (): void {
        $viewer = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'viewer',
        );

        expect($viewer->permissions)->toContain('purchasing.suppliers.view')
            ->and($viewer->permissions)->not->toContain('purchasing.suppliers.manage');
    });

    it('grants management to exactly the administrator, accountant and bookkeeper', function (): void {
        $managing = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $template): bool => $template->isOwner)
            ->filter(static fn (RoleTemplate $template): bool => in_array('purchasing.suppliers.manage', $template->permissions, true))
            ->map(static fn (RoleTemplate $template): string => $template->name)
            ->values()
            ->all();

        // `administrator` is built from `tenantGrantableNames()`, so it holds every grantable capability
        // and picks up new ones automatically. Asserted as an exact set rather than a count, so granting
        // it to a fourth template — or leaking it to the viewer — fails this test rather than passing
        // unnoticed.
        expect($managing)->toBe(['administrator', 'accountant', 'bookkeeper']);
    });
});

describe('the accountant', function (): void {
    it('may read and manage suppliers', function (): void {
        $accountant = supplierMemberWithRole('accountant', 'sup-acct@acme.test');

        expect($accountant->can('viewAny', Supplier::class))->toBeTrue()
            ->and($accountant->can('view', $this->supplier))->toBeTrue()
            ->and($accountant->can('create', Supplier::class))->toBeTrue()
            ->and($accountant->can('update', $this->supplier))->toBeTrue();
    });

    it('may run every lifecycle operation', function (): void {
        $accountant = supplierMemberWithRole('accountant', 'sup-acct2@acme.test');

        // Each asserted rather than assumed from `update`, because the policy could later diverge and a
        // single check would stop covering the rest.
        expect($accountant->can('archive', $this->supplier))->toBeTrue()
            ->and($accountant->can('delete', $this->supplier))->toBeTrue()
            ->and($accountant->can('restore', $this->supplier))->toBeTrue();
    });
});

describe('the bookkeeper', function (): void {
    it('may read and manage suppliers', function (): void {
        $bookkeeper = supplierMemberWithRole('bookkeeper', 'sup-book@acme.test');

        expect($bookkeeper->can('view', $this->supplier))->toBeTrue()
            ->and($bookkeeper->can('create', Supplier::class))->toBeTrue()
            ->and($bookkeeper->can('update', $this->supplier))->toBeTrue()
            ->and($bookkeeper->can('archive', $this->supplier))->toBeTrue()
            ->and($bookkeeper->can('delete', $this->supplier))->toBeTrue()
            ->and($bookkeeper->can('restore', $this->supplier))->toBeTrue();
    });
});

describe('the viewer', function (): void {
    it('may read but not change', function (): void {
        $viewer = supplierMemberWithRole('viewer', 'sup-view@acme.test');

        expect($viewer->can('view', $this->supplier))->toBeTrue()
            ->and($viewer->can('create', Supplier::class))->toBeFalse()
            ->and($viewer->can('update', $this->supplier))->toBeFalse()
            ->and($viewer->can('archive', $this->supplier))->toBeFalse()
            ->and($viewer->can('delete', $this->supplier))->toBeFalse()
            ->and($viewer->can('restore', $this->supplier))->toBeFalse();
    });
});

describe('company and tenant boundaries', function (): void {
    it('refuses a user with the capability but no membership of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'sup-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both. This user holds
        // `purchasing.suppliers.manage` and no membership of the company.
        expect($fresh->can('update', $this->supplier))->toBeFalse()
            ->and($fresh->can('view', $this->supplier))->toBeFalse();
    });

    it('refuses a member of a different company in the same workspace', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'sup-second@acme.test']);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Membership of one company is not membership of its sibling. Row level security cannot make this
        // distinction — both companies share a tenant — so only the policy does.
        expect($fresh->can('view', $this->supplier))->toBeFalse()
            ->and($fresh->can('update', $this->supplier))->toBeFalse();
    });

    it('still allows the owner everything', function (): void {
        $owner = RowLevelSecurity::bypass(fn () => $this->owner->fresh());

        // `Gate::before` short-circuits every ability for a tenant owner, who holds the wildcard grant. It
        // is why state preconditions (owing money) live in the service, not the policy.
        expect($owner->can('update', $this->supplier))->toBeTrue()
            ->and($owner->can('delete', $this->supplier))->toBeTrue();
    });
});

describe('provider registration', function (): void {
    it('resolves the policy for the model', function (): void {
        expect(Gate::getPolicyFor(Supplier::class))->toBeInstanceOf(SupplierPolicy::class);
    });

    it('resolves the service as a singleton', function (): void {
        // Registered as a singleton, which is what makes the probe-rebinding pattern in the service tests
        // need `forgetInstance`. Asserted so that contract is explicit rather than folklore.
        expect(app(SupplierService::class))->toBeInstanceOf(SupplierService::class)
            ->and(app(SupplierService::class))->toBe(app(SupplierService::class));
    });

    it('registers the supplier morph alias', function (): void {
        // `Supplier` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped
        // class throws rather than storing a class name a namespace refactor would orphan.
        expect(Relation::getMorphedModel(Supplier::MORPH_ALIAS))->toBe(Supplier::class);
    });

    it('binds the payables probe to the real EloquentPayableBalanceProbe now bills exist', function (): void {
        // Wave 7 flipped this from the dormant `NoPayables` to `EloquentPayableBalanceProbe`, activating the
        // three dormant supplier rules. A seam left unbound is exactly the failure this assertion catches
        // (ADR 0018 §E, ADR 0019 §E). `NoPayables` is kept in the codebase but is no longer the bound default.
        expect(app(PayableBalanceProbe::class))->toBeInstanceOf(EloquentPayableBalanceProbe::class);
    });
});
