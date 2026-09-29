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
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Asids\Core\Purchasing\Policies\PaymentPolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and post supplier payments, and how the module is wired — Stage 5 of Wave 8a (ADR 0020 §E).
 *
 * The payable-side mirror of `BillAuthorizationTest`, with the draft/post split collapsed to `{view, post}`:
 * record-and-post is one step (Gate-1 dec. 5), so there is no `.draft`. `post` is the sensitive capability
 * (it moves money and commits to the ledger); `view` is not. Two genuinely separate questions on `view`: does
 * the role hold the capability, and is the user a member of the company whose books they are touching.
 *
 * `create()` is "record and post", checking only the `post` capability — there is no model yet, so company
 * membership is validated by `PaymentService` against the target company, exactly as `BillPolicy::create`
 * checks only the permission.
 *
 * Wave 8c adds `purchasing.payments.cancel` — the sensitive capability that reverses a posted payment — now
 * that `PaymentService::cancel()` exists to check it (ADR 0023 §E). It is granted to exactly the roles that hold
 * `post` (administrator, accountant; owner implicitly).
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Silva Suppliers', code: 'SILVA'));

    $bill = app(BillService::class)->post(
        app(BillService::class)->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'SUP-INV-001',
            lines: [new BillLineData(
                description: 'Office supplies',
                quantity: '1',
                unitPrice: '1000.00',
                expenseAccountId: (string) $this->purchases->getKey(),
            )],
        )),
        $this->owner,
    );

    $this->payment = app(PaymentService::class)->record($this->company, new PaymentData(
        supplierId: (string) $this->supplier->getKey(),
        bankAccountId: (string) $this->bank->getKey(),
        paymentDate: CarbonImmutable::parse('2026-06-15'),
        amount: '1000.00',
        allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
    ), $this->owner);
});

/**
 * A member of the acme company holding the given role. Named to stay clear of the other suites' helpers.
 */
function paymentMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares the payment capabilities: view, post and cancel — no draft', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('purchasing.payments.view')
            ->and($names)->toContain('purchasing.payments.post')
            // Wave 8c adds cancel now that the code checking it exists (ADR 0023 §E).
            ->and($names)->toContain('purchasing.payments.cancel')
            // Record-and-post is one step (dec. 5) — no draft. WHT (Wave 8b) reuses `payments.post` and adds NO
            // capability of its own (ADR 0021 §C4) — the scope-creep guard.
            ->and($names)->not->toContain('purchasing.payments.draft')
            ->and($names)->not->toContain('purchasing.payments.wht');
    });

    it('marks posting and cancelling sensitive, and viewing not', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        expect($definitions['purchasing.payments.view']->sensitive)->toBeFalse()
            ->and($definitions['purchasing.payments.post']->sensitive)->toBeTrue()
            ->and($definitions['purchasing.payments.cancel']->sensitive)->toBeTrue();
    });

    it('orders the payment capabilities after the bill ones in the purchasing group', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // suppliers 10/20, bills 30/40/50; payments continue 60/70/80 (ADR §E1, 0023 §E).
        expect($definitions['purchasing.payments.view']->sortOrder)->toBe(60)
            ->and($definitions['purchasing.payments.post']->sortOrder)->toBe(70)
            ->and($definitions['purchasing.payments.cancel']->sortOrder)->toBe(80);
    });
});

describe('role grants', function (): void {
    it('gives the accountant view, post and cancel', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'accountant',
        );

        expect($template->permissions)->toContain('purchasing.payments.view')
            ->and($template->permissions)->toContain('purchasing.payments.post')
            ->and($template->permissions)->toContain('purchasing.payments.cancel');
    });

    it('lets the bookkeeper view but not post', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );

        expect($template->permissions)->toContain('purchasing.payments.view')
            ->and($template->permissions)->not->toContain('purchasing.payments.post');
    });

    it('gives the viewer read access only', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($template->permissions)->toContain('purchasing.payments.view')
            ->and($template->permissions)->not->toContain('purchasing.payments.post');
    });

    it('grants viewing to exactly the administrator, accountant, bookkeeper and viewer', function (): void {
        $viewing = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('purchasing.payments.view', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        expect($viewing)->toBe(['administrator', 'accountant', 'bookkeeper', 'viewer']);
    });

    it('grants posting to exactly the administrator and accountant', function (): void {
        $posting = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('purchasing.payments.post', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // Narrower than viewing by exactly the bookkeeper and viewer — the money-moving action.
        expect($posting)->toBe(['administrator', 'accountant']);
    });

    it('grants cancelling to exactly the administrator and accountant', function (): void {
        $cancelling = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('purchasing.payments.cancel', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // The same reversing-posted-history authority as posting — held by exactly the roles that hold post.
        expect($cancelling)->toBe(['administrator', 'accountant']);
    });
});

describe('the accountant', function (): void {
    it('may read, post (record) and cancel', function (): void {
        $accountant = paymentMemberWithRole('accountant', 'pay-acct@acme.test');

        expect($accountant->can('viewAny', SupplierPayment::class))->toBeTrue()
            ->and($accountant->can('view', $this->payment))->toBeTrue()
            ->and($accountant->can('create', SupplierPayment::class))->toBeTrue()
            ->and($accountant->can('cancel', $this->payment))->toBeTrue();
    });
});

describe('the bookkeeper', function (): void {
    it('may read but not post', function (): void {
        $bookkeeper = paymentMemberWithRole('bookkeeper', 'pay-book@acme.test');

        expect($bookkeeper->can('viewAny', SupplierPayment::class))->toBeTrue()
            ->and($bookkeeper->can('view', $this->payment))->toBeTrue()
            ->and($bookkeeper->can('create', SupplierPayment::class))->toBeFalse()
            ->and($bookkeeper->can('cancel', $this->payment))->toBeFalse();
    });
});

describe('the viewer', function (): void {
    it('may read but not post', function (): void {
        $viewer = paymentMemberWithRole('viewer', 'pay-view@acme.test');

        expect($viewer->can('view', $this->payment))->toBeTrue()
            ->and($viewer->can('viewAny', SupplierPayment::class))->toBeTrue()
            ->and($viewer->can('create', SupplierPayment::class))->toBeFalse()
            ->and($viewer->can('cancel', $this->payment))->toBeFalse();
    });
});

describe('company and tenant boundaries', function (): void {
    it('refuses a user with the capability but no membership of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'pay-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        // Permission and membership are different questions and the policy asks both on `view` and `cancel`.
        expect($fresh->can('view', $this->payment))->toBeFalse()
            ->and($fresh->can('cancel', $this->payment))->toBeFalse();
    });

    it('refuses a member of a sibling company in the same workspace', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'pay-sib@acme.test']);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Row level security cannot make this distinction — both companies share a tenant — so only the policy does.
        expect($fresh->can('view', $this->payment))->toBeFalse()
            ->and($fresh->can('cancel', $this->payment))->toBeFalse();
    });

    it('still allows the owner everything through the Gate::before short circuit', function (): void {
        $owner = RowLevelSecurity::bypass(fn () => $this->owner->fresh());

        expect($owner->can('view', $this->payment))->toBeTrue()
            ->and($owner->can('create', SupplierPayment::class))->toBeTrue()
            ->and($owner->can('cancel', $this->payment))->toBeTrue();
    });
});

describe('provider registration', function (): void {
    it('resolves the policy for the supplier payment model', function (): void {
        expect(Gate::getPolicyFor(SupplierPayment::class))->toBeInstanceOf(PaymentPolicy::class);
    });

    it('resolves the payment service as a singleton', function (): void {
        expect(app(PaymentService::class))->toBeInstanceOf(PaymentService::class)
            ->and(app(PaymentService::class))->toBe(app(PaymentService::class));
    });

    it('registers the supplier payment morph alias', function (): void {
        expect(Relation::getMorphedModel(SupplierPayment::MORPH_ALIAS))->toBe(SupplierPayment::class);
    });
});
