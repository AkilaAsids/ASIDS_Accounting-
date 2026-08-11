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
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\InvoiceTotalsCalculator;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Asids\Core\Sales\Policies\SalesInvoicePolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and change sales invoices, and how the module is wired.
 *
 * Stage 3 of Milestone 4. Two questions, genuinely separate: does the role hold the capability, and is the user
 * a member of the company whose sales ledger they are touching. A test checking only the first would pass for a
 * user with no business in those books at all.
 *
 * The contrast with tax codes is the substance here. Tax-code management is held by one template because a wrong
 * rate misstates every invoice and the return. Invoice *drafting* is held by two, because a draft has no number,
 * is not in the ledger, and the customer has never seen it. The capability that deserves the narrow grant is
 * issuing, and it arrives with Milestone 5 rather than being declared unused now.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->invoice = app(SalesInvoiceService::class)->createDraft($this->company, new SalesInvoiceData(
        customerId: (string) $this->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) $this->revenue->getKey(),
        )],
    ));
});

/**
 * A member of the acme company holding the given role. Named to stay clear of the other Sales suites' helpers,
 * which are global in Pest.
 */
function invoiceMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares both invoice capabilities', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('sales.invoices.view')
            ->and($names)->toContain('sales.invoices.draft');
    });

    it('does not declare issuing or cancellation yet', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        // Milestone 5's capabilities are absent on purpose. Declaring authorisation for an operation that does
        // not exist means writing a guard nobody can test, which is how a guard ends up protecting the wrong
        // thing.
        expect($names)->not->toContain('sales.invoices.issue')
            ->and($names)->not->toContain('sales.invoices.cancel');
    });

    it('marks neither invoice capability sensitive', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // Deliberate, and the contrast with `sales.tax-codes.manage` is the point: a draft can be corrected or
        // deleted with no trace and no consequence. Issuing is what will carry the marker.
        expect($definitions['sales.invoices.view']->sensitive)->toBeFalse()
            ->and($definitions['sales.invoices.draft']->sensitive)->toBeFalse();
    });
});

describe('role grants', function (): void {
    it('gives the accountant both capabilities', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'accountant',
        );

        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->toContain('sales.invoices.draft');
    });

    it('gives the bookkeeper both capabilities', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );

        // Unlike tax codes, which a bookkeeper may only read. Drafting an invoice is the ordinary day-to-day
        // work this role exists for, and the split that matters is issuing.
        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->toContain('sales.invoices.draft');
    });

    it('gives the viewer read access only', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->not->toContain('sales.invoices.draft');
    });

    it('grants drafting to exactly the templates intended', function (): void {
        $drafting = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('sales.invoices.draft', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // `administrator` is `PermissionCatalogue::tenantGrantableNames()`, so it picks up every new capability
        // automatically — designed behaviour from ADR 0003, not an oversight. Asserted as an exact set so adding
        // a fourth is a change that fails this test rather than passing unnoticed.
        expect($drafting)->toBe(['administrator', 'accountant', 'bookkeeper']);
    });
});

describe('the accountant and the bookkeeper', function (): void {
    it('lets an accountant read and change drafts', function (string $role, string $email): void {
        $user = invoiceMemberWithRole($role, $email);

        expect($user->can('viewAny', SalesInvoice::class))->toBeTrue()
            ->and($user->can('view', $this->invoice))->toBeTrue()
            ->and($user->can('create', SalesInvoice::class))->toBeTrue()
            ->and($user->can('update', $this->invoice))->toBeTrue()
            ->and($user->can('delete', $this->invoice))->toBeTrue();
    })->with([
        ['accountant', 'inv-acct@acme.test'],
        ['bookkeeper', 'inv-book@acme.test'],
    ]);
});

describe('the viewer', function (): void {
    it('may read but not change', function (): void {
        $viewer = invoiceMemberWithRole('viewer', 'inv-view@acme.test');

        expect($viewer->can('view', $this->invoice))->toBeTrue()
            ->and($viewer->can('viewAny', SalesInvoice::class))->toBeTrue()
            ->and($viewer->can('create', SalesInvoice::class))->toBeFalse()
            ->and($viewer->can('update', $this->invoice))->toBeFalse()
            ->and($viewer->can('delete', $this->invoice))->toBeFalse();
    });
});

describe('company and tenant boundaries', function (): void {
    it('refuses a user with the capability but no membership', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'inv-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both.
        expect($fresh->can('view', $this->invoice))->toBeFalse()
            ->and($fresh->can('update', $this->invoice))->toBeFalse();
    });

    it('refuses a member of a sibling company in the same workspace', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'inv-sib@acme.test']);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Row level security cannot make this distinction — both companies share a tenant — so only the policy
        // does.
        expect($fresh->can('view', $this->invoice))->toBeFalse()
            ->and($fresh->can('update', $this->invoice))->toBeFalse();
    });

    it('still allows the owner everything', function (): void {
        $owner = RowLevelSecurity::bypass(fn () => $this->owner->fresh());

        // `Gate::before` short-circuits every ability for a tenant owner. Asserted so the behaviour is recorded
        // rather than rediscovered: it is why the draft-only precondition lives in the service, not the policy.
        expect($owner->can('update', $this->invoice))->toBeTrue()
            ->and($owner->can('delete', $this->invoice))->toBeTrue();
    });
});

describe('provider registration', function (): void {
    it('resolves the policy for the invoice model', function (): void {
        expect(Gate::getPolicyFor(SalesInvoice::class))->toBeInstanceOf(SalesInvoicePolicy::class);
    });

    it('registers no policy for lines', function (): void {
        // A line is not independently addressable. Authorising one separately would invite a caller to reach for
        // a line without its document.
        expect(Gate::getPolicyFor(SalesInvoiceLine::class))->toBeNull();
    });

    it('resolves both invoice services as singletons', function (): void {
        expect(app(SalesInvoiceService::class))->toBeInstanceOf(SalesInvoiceService::class)
            ->and(app(SalesInvoiceService::class))->toBe(app(SalesInvoiceService::class))
            ->and(app(InvoiceTotalsCalculator::class))->toBe(app(InvoiceTotalsCalculator::class));
    });

    it('registers both invoice morph aliases', function (): void {
        // `SalesInvoice` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped
        // class throws rather than storing a class name a rename would orphan. The line alias is registered too,
        // because Milestone 5's `SourceDocument` will refuse an unmapped model.
        expect(Relation::getMorphedModel(SalesInvoice::MORPH_ALIAS))->toBe(SalesInvoice::class)
            ->and(Relation::getMorphedModel(SalesInvoiceLine::MORPH_ALIAS))->toBe(SalesInvoiceLine::class);
    });
});
