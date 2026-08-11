<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Application\Services\TaxRateResolver;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Sales\Infrastructure\NoTaxRateUsage;
use Asids\Core\Sales\Policies\TaxCodePolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and change tax codes.
 *
 * Stage 4 of Milestone 3. Two questions are tested and they are genuinely separate: does the role hold the
 * capability, and is the user a member of the company whose configuration they are touching. Both must be
 * true, and a test that only checked the first would pass for a user with no business in those books at all.
 *
 * The read/manage split is the substance here. Seeing which codes exist is what anyone raising an invoice
 * needs; deciding what they charge changes every invoice under the code, the ledger's tax liability and the
 * return — so only the accountant template holds it.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->taxCode = app(TaxCodeService::class)->create($this->company, new TaxCodeData(
        code: 'VAT',
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: '18',
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: (string) Account::query()
            ->forCompany($this->company->getKey())
            ->where('code', '2140')
            ->firstOrFail()
            ->getKey(),
    ));
});

/**
 * A member of the acme company holding the given role.
 *
 * Named `taxMemberWithRole` rather than anything generic: Pest helpers are global, and a collision takes the
 * whole suite down rather than one file.
 */
function taxMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares both tax-code capabilities', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('sales.tax-codes.view')
            ->and($names)->toContain('sales.tax-codes.manage');
    });

    it('marks management sensitive but viewing not', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // Of everything in the sales group this is the one that most deserves the marker: a wrong rate
        // misstates every invoice and the return while the books still balance.
        expect($definitions['sales.tax-codes.manage']->sensitive)->toBeTrue()
            ->and($definitions['sales.tax-codes.view']->sensitive)->toBeFalse();
    });
});

describe('role grants', function (): void {
    it('gives the accountant both capabilities', function (): void {
        $accountant = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'accountant',
        );

        expect($accountant->permissions)->toContain('sales.tax-codes.view')
            ->and($accountant->permissions)->toContain('sales.tax-codes.manage');
    });

    it('gives the bookkeeper view only', function (): void {
        $bookkeeper = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'bookkeeper',
        );

        // The same reasoning as the drafting/posting split: a bookkeeper records what happened, an
        // accountant decides what the rate should be.
        expect($bookkeeper->permissions)->toContain('sales.tax-codes.view')
            ->and($bookkeeper->permissions)->not->toContain('sales.tax-codes.manage');
    });

    it('gives the viewer view only', function (): void {
        $viewer = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $template): bool => $template->name === 'viewer',
        );

        expect($viewer->permissions)->toContain('sales.tax-codes.view')
            ->and($viewer->permissions)->not->toContain('sales.tax-codes.manage');
    });

    it('grants management to the administrator by construction and the accountant by choice', function (): void {
        $managing = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $template): bool => $template->isOwner)
            ->filter(static fn (RoleTemplate $template): bool => in_array('sales.tax-codes.manage', $template->permissions, true))
            ->map(static fn (RoleTemplate $template): string => $template->name)
            ->values()
            ->all();

        // `administrator` is built from `PermissionCatalogue::tenantGrantableNames()`, so it holds every
        // grantable capability and picks up new ones automatically — that is the designed behaviour, not an
        // oversight. `accountant` is the only template that names this capability deliberately.
        //
        // Asserted as an exact set rather than a count, so adding it to a third template is a change that
        // fails this test rather than passing unnoticed.
        expect($managing)->toBe(['administrator', 'accountant']);
    });
});

describe('the accountant', function (): void {
    it('may read and change tax codes', function (): void {
        $accountant = taxMemberWithRole('accountant', 'tax-acct@acme.test');

        expect($accountant->can('viewAny', TaxCode::class))->toBeTrue()
            ->and($accountant->can('view', $this->taxCode))->toBeTrue()
            ->and($accountant->can('create', TaxCode::class))->toBeTrue()
            ->and($accountant->can('update', $this->taxCode))->toBeTrue();
    });

    it('may run every lifecycle operation', function (): void {
        $accountant = taxMemberWithRole('accountant', 'tax-acct2@acme.test');

        expect($accountant->can('endRange', $this->taxCode))->toBeTrue()
            ->and($accountant->can('deactivate', $this->taxCode))->toBeTrue()
            ->and($accountant->can('reactivate', $this->taxCode))->toBeTrue()
            ->and($accountant->can('delete', $this->taxCode))->toBeTrue()
            ->and($accountant->can('restore', $this->taxCode))->toBeTrue();
    });
});

describe('the bookkeeper', function (): void {
    it('may read but not change', function (): void {
        $bookkeeper = taxMemberWithRole('bookkeeper', 'tax-book@acme.test');

        expect($bookkeeper->can('viewAny', TaxCode::class))->toBeTrue()
            ->and($bookkeeper->can('view', $this->taxCode))->toBeTrue()
            ->and($bookkeeper->can('create', TaxCode::class))->toBeFalse()
            ->and($bookkeeper->can('update', $this->taxCode))->toBeFalse();
    });

    it('is refused every lifecycle operation', function (): void {
        $bookkeeper = taxMemberWithRole('bookkeeper', 'tax-book2@acme.test');

        // Each asserted rather than assumed from `update`, because the policy could later diverge and a
        // single check would stop covering the rest.
        expect($bookkeeper->can('endRange', $this->taxCode))->toBeFalse()
            ->and($bookkeeper->can('deactivate', $this->taxCode))->toBeFalse()
            ->and($bookkeeper->can('reactivate', $this->taxCode))->toBeFalse()
            ->and($bookkeeper->can('delete', $this->taxCode))->toBeFalse()
            ->and($bookkeeper->can('restore', $this->taxCode))->toBeFalse();
    });
});

describe('the viewer', function (): void {
    it('may read but not change', function (): void {
        $viewer = taxMemberWithRole('viewer', 'tax-view@acme.test');

        expect($viewer->can('view', $this->taxCode))->toBeTrue()
            ->and($viewer->can('create', TaxCode::class))->toBeFalse()
            ->and($viewer->can('update', $this->taxCode))->toBeFalse()
            ->and($viewer->can('delete', $this->taxCode))->toBeFalse();
    });
});

describe('company and tenant boundaries', function (): void {
    it('refuses a user with the capability but no membership of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'tax-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both. This user holds
        // `sales.tax-codes.manage` and no membership of the company.
        expect($fresh->can('update', $this->taxCode))->toBeFalse()
            ->and($fresh->can('view', $this->taxCode))->toBeFalse();
    });

    it('refuses a member of a different company in the same workspace', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'tax-second@acme.test']);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Membership of one company is not membership of its sibling. Row level security cannot make this
        // distinction — both companies share a tenant — so only the policy does.
        expect($fresh->can('view', $this->taxCode))->toBeFalse()
            ->and($fresh->can('update', $this->taxCode))->toBeFalse();
    });

    it('still allows the owner everything', function (): void {
        $owner = RowLevelSecurity::bypass(fn () => $this->owner->fresh());

        // `Gate::before` short-circuits every ability for a tenant owner. Asserted so the behaviour is
        // recorded rather than discovered: it is why state preconditions live in the service, not the policy.
        expect($owner->can('update', $this->taxCode))->toBeTrue()
            ->and($owner->can('delete', $this->taxCode))->toBeTrue();
    });
});

describe('provider registration', function (): void {
    it('resolves the policy for the model', function (): void {
        expect(Gate::getPolicyFor(TaxCode::class))->toBeInstanceOf(TaxCodePolicy::class);
    });

    it('resolves both tax services from the container', function (): void {
        expect(app(TaxCodeService::class))->toBeInstanceOf(TaxCodeService::class)
            ->and(app(TaxRateResolver::class))->toBeInstanceOf(TaxRateResolver::class);
    });

    it('resolves the service as a singleton', function (): void {
        // Registered as a singleton, which is what makes the probe-rebinding pattern in the service tests
        // need `forgetInstance`. Asserted so that contract is explicit rather than folklore.
        expect(app(TaxCodeService::class))->toBe(app(TaxCodeService::class));
    });

    it('registers the tax-code morph alias', function (): void {
        // `TaxCode` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped
        // class throws rather than storing a class name a rename would orphan.
        expect(Relation::getMorphedModel(TaxCode::MORPH_ALIAS))
            ->toBe(TaxCode::class);
    });

    it('binds the rate-usage probe to the current-schema implementation', function (): void {
        expect(app(TaxRateUsageProbe::class))
            ->toBeInstanceOf(NoTaxRateUsage::class);
    });
});
