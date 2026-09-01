<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Policies\BillPolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read, draft and post bills, and how the module is wired — Stage 7 of Wave 7 (ADR 0019 §F).
 *
 * The payable-side mirror of `SalesInvoiceAuthorizationTest`. Bills are a document with a draft→post lifecycle,
 * so they mirror `sales.invoices.*` (view/draft/post), NOT the supplier master-data split — `draft` (ordinary
 * work) is not sensitive; `post` (commits to the ledger) is. Two questions, genuinely separate: does the role
 * hold the capability, and is the user a member of the company whose books they are touching.
 *
 * No `purchasing.bills.cancel` this wave — cancellation is deferred, and the catalogue rule is "only add a
 * capability when the code that checks it exists".
 *
 * RED expectation before Stage 7 lands: the `purchasing.bills.*` permissions, the role grants and `BillPolicy`
 * do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

    $this->bill = app(BillService::class)->createDraft($this->company, new BillData(
        supplierId: (string) $this->supplier->getKey(),
        billDate: CarbonImmutable::parse('2026-06-15'),
        supplierInvoiceNumber: 'SUP-INV-001',
        lines: [new BillLineData(
            description: 'Office supplies',
            quantity: '1',
            unitPrice: '1000.00',
            expenseAccountId: (string) $this->purchases->getKey(),
        )],
    ));
});

/**
 * A member of the acme company holding the given role. Named to stay clear of the other suites' helpers.
 */
function billMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares the three bill capabilities', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('purchasing.bills.view')
            ->and($names)->toContain('purchasing.bills.draft')
            ->and($names)->toContain('purchasing.bills.post')
            // No cancel this wave — cancellation is deferred (Gate-1 dec. 2).
            ->and($names)->not->toContain('purchasing.bills.cancel');
    });

    it('marks posting sensitive, and viewing and drafting not', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // The contrast with the sales mirror: a draft has no number, is not in the ledger. Posting commits it.
        expect($definitions['purchasing.bills.view']->sensitive)->toBeFalse()
            ->and($definitions['purchasing.bills.draft']->sensitive)->toBeFalse()
            ->and($definitions['purchasing.bills.post']->sensitive)->toBeTrue();
    });

    it('orders the bill capabilities after the supplier ones in the purchasing group', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // suppliers are 10/20; bills continue 30/40/50 (ADR §F1).
        expect($definitions['purchasing.bills.view']->sortOrder)->toBe(30)
            ->and($definitions['purchasing.bills.draft']->sortOrder)->toBe(40)
            ->and($definitions['purchasing.bills.post']->sortOrder)->toBe(50);
    });
});

describe('role grants', function (): void {
    it('gives the accountant all three capabilities', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'accountant',
        );

        expect($template->permissions)->toContain('purchasing.bills.view')
            ->and($template->permissions)->toContain('purchasing.bills.draft')
            ->and($template->permissions)->toContain('purchasing.bills.post');
    });

    it('lets the bookkeeper draft but not post', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );

        expect($template->permissions)->toContain('purchasing.bills.view')
            ->and($template->permissions)->toContain('purchasing.bills.draft')
            ->and($template->permissions)->not->toContain('purchasing.bills.post');
    });

    it('gives the viewer read access only', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($template->permissions)->toContain('purchasing.bills.view')
            ->and($template->permissions)->not->toContain('purchasing.bills.draft')
            ->and($template->permissions)->not->toContain('purchasing.bills.post');
    });

    it('grants drafting to exactly the administrator, accountant and bookkeeper', function (): void {
        $drafting = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('purchasing.bills.draft', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // `administrator` is the whole grantable catalogue, so it picks up new capabilities automatically.
        // Asserted as an exact set so a stray grant fails this test rather than passing unnoticed.
        expect($drafting)->toBe(['administrator', 'accountant', 'bookkeeper']);
    });

    it('grants posting to exactly the administrator and accountant', function (): void {
        $posting = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('purchasing.bills.post', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // Narrower than drafting by exactly the bookkeeper, which is the point of the split.
        expect($posting)->toBe(['administrator', 'accountant']);
    });
});

describe('the accountant', function (): void {
    it('may read, draft and post', function (): void {
        $accountant = billMemberWithRole('accountant', 'bill-acct@acme.test');

        expect($accountant->can('viewAny', Bill::class))->toBeTrue()
            ->and($accountant->can('view', $this->bill))->toBeTrue()
            ->and($accountant->can('create', Bill::class))->toBeTrue()
            ->and($accountant->can('update', $this->bill))->toBeTrue()
            ->and($accountant->can('delete', $this->bill))->toBeTrue()
            ->and($accountant->can('post', $this->bill))->toBeTrue();
    });

    it('is refused posting once the bill is no longer a draft (advisory state check)', function (): void {
        $accountant = billMemberWithRole('accountant', 'bill-acct2@acme.test');

        $posted = app(BillService::class)->post($this->bill, $this->owner);

        // The `isDraft()` in the policy is advisory — the capability is held; the bill is simply no longer a
        // draft. `BillService::post()` re-checks the state as the enforcement.
        expect($accountant->can('post', $posted->refresh()))->toBeFalse();
    });
});

describe('the bookkeeper', function (): void {
    it('may draft but not post', function (): void {
        $bookkeeper = billMemberWithRole('bookkeeper', 'bill-book@acme.test');

        expect($bookkeeper->can('view', $this->bill))->toBeTrue()
            ->and($bookkeeper->can('create', Bill::class))->toBeTrue()
            ->and($bookkeeper->can('update', $this->bill))->toBeTrue()
            ->and($bookkeeper->can('delete', $this->bill))->toBeTrue()
            ->and($bookkeeper->can('post', $this->bill))->toBeFalse();
    });
});

describe('the viewer', function (): void {
    it('may read but not draft or post', function (): void {
        $viewer = billMemberWithRole('viewer', 'bill-view@acme.test');

        expect($viewer->can('view', $this->bill))->toBeTrue()
            ->and($viewer->can('viewAny', Bill::class))->toBeTrue()
            ->and($viewer->can('create', Bill::class))->toBeFalse()
            ->and($viewer->can('update', $this->bill))->toBeFalse()
            ->and($viewer->can('post', $this->bill))->toBeFalse();
    });
});

describe('company and tenant boundaries', function (): void {
    it('refuses a user with the capability but no membership of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'bill-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both.
        expect($fresh->can('view', $this->bill))->toBeFalse()
            ->and($fresh->can('update', $this->bill))->toBeFalse()
            ->and($fresh->can('post', $this->bill))->toBeFalse();
    });

    it('refuses a member of a sibling company in the same workspace', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'bill-sib@acme.test']);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Row level security cannot make this distinction — both companies share a tenant — so only the policy does.
        expect($fresh->can('view', $this->bill))->toBeFalse()
            ->and($fresh->can('post', $this->bill))->toBeFalse();
    });

    it('still allows the owner everything through the Gate::before short circuit', function (): void {
        $owner = RowLevelSecurity::bypass(fn () => $this->owner->fresh());

        // The owner holds the wildcard grant, which is why the draft-only precondition lives in the service.
        expect($owner->can('update', $this->bill))->toBeTrue()
            ->and($owner->can('delete', $this->bill))->toBeTrue()
            ->and($owner->can('post', $this->bill))->toBeTrue();
    });
});

describe('provider registration', function (): void {
    it('resolves the policy for the bill model', function (): void {
        expect(Gate::getPolicyFor(Bill::class))->toBeInstanceOf(BillPolicy::class);
    });

    it('resolves the bill service as a singleton', function (): void {
        expect(app(BillService::class))->toBeInstanceOf(BillService::class)
            ->and(app(BillService::class))->toBe(app(BillService::class));
    });

    it('registers the bill morph alias', function (): void {
        // `Bill` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped class
        // throws. It is also what `SourceDocument` feeds back when a posted bill cites its ledger entry.
        expect(\Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel(Bill::MORPH_ALIAS))->toBe(Bill::class);
    });
});
