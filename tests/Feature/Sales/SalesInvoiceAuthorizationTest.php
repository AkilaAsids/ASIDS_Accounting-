<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
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
use Illuminate\Support\Facades\DB;
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

    // Added by Stage 5: the issuing and cancellation groups post to the ledger, which needs a period to post
    // into. Harmless to the drafting groups, which never reach the calendar.
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

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
    it('declares all four invoice capabilities', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        // Issuing and cancellation arrived with Milestone 5 Stage 5, alongside the transitions they guard —
        // which is why they were absent until now rather than declared against operations nobody could call.
        expect($names)->toContain('sales.invoices.view')
            ->and($names)->toContain('sales.invoices.draft')
            ->and($names)->toContain('sales.invoices.issue')
            ->and($names)->toContain('sales.invoices.cancel');
    });

    it('marks issuing and cancellation sensitive, and drafting not', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // The contrast is the point. A draft has no number, is not in the ledger and the customer has never
        // seen it, so it can be corrected or deleted with no trace. Issuing consumes a number from a gapless
        // series and posts to the books; cancelling reverses a posting that is now permanent.
        expect($definitions['sales.invoices.view']->sensitive)->toBeFalse()
            ->and($definitions['sales.invoices.draft']->sensitive)->toBeFalse()
            ->and($definitions['sales.invoices.issue']->sensitive)->toBeTrue()
            ->and($definitions['sales.invoices.cancel']->sensitive)->toBeTrue();
    });

    it('orders the invoice capabilities as they escalate', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        // Read, prepare, commit, undo. The order is what a permissions screen shows a customer, so it should
        // read as increasing consequence rather than as the order someone happened to add them.
        expect($definitions['sales.invoices.view']->sortOrder)->toBe(50)
            ->and($definitions['sales.invoices.draft']->sortOrder)->toBe(60)
            ->and($definitions['sales.invoices.issue']->sortOrder)->toBe(70)
            ->and($definitions['sales.invoices.cancel']->sortOrder)->toBe(80);
    });
});

describe('role grants', function (): void {
    it('gives the accountant all four capabilities', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'accountant',
        );

        // The same side of the split this template holds for the ledger, where it has
        // `accounting.journals.post` and `.reverse`.
        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->toContain('sales.invoices.draft')
            ->and($template->permissions)->toContain('sales.invoices.issue')
            ->and($template->permissions)->toContain('sales.invoices.cancel');
    });

    it('lets the bookkeeper draft but neither issue nor cancel', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );

        // The whole reason the split exists: a bookkeeper records what was sold, and someone else commits it
        // to the ledger and the customer — or reverses that commitment.
        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->toContain('sales.invoices.draft')
            ->and($template->permissions)->not->toContain('sales.invoices.issue')
            ->and($template->permissions)->not->toContain('sales.invoices.cancel');
    });

    it('gives the viewer read access only', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($template->permissions)->toContain('sales.invoices.view')
            ->and($template->permissions)->not->toContain('sales.invoices.draft')
            ->and($template->permissions)->not->toContain('sales.invoices.issue')
            ->and($template->permissions)->not->toContain('sales.invoices.cancel');
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

    it('grants issuing and cancellation to exactly the templates intended', function (string $permission): void {
        $holders = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array($permission, $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // Narrower than drafting by exactly one role, which is the point of adding them at all. `administrator`
        // is there because it is the whole grantable catalogue — the automatic inheritance ADR 0003 designed.
        expect($holders)->toBe(['administrator', 'accountant']);
    })->with(['sales.invoices.issue', 'sales.invoices.cancel']);
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

describe('reaching an existing workspace', function (): void {
    it('synchronises the new capabilities into a workspace that predates them', function (): void {
        // The rollout question, and the reason it needs asserting: permissions are code, not a migration, so a
        // workspace provisioned before Stage 5 acquires these only when the synchroniser runs. Deleting the
        // rows and re-running reproduces that workspace without needing one.
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->whereIn('name', ['sales.invoices.issue', 'sales.invoices.cancel'])->delete();
        });

        expect(RowLevelSecurity::bypass(static fn (): int => DB::table('permissions')
            ->whereIn('name', ['sales.invoices.issue', 'sales.invoices.cancel'])->count()))->toBe(0);

        $result = RowLevelSecurity::bypass(static fn (): array => app(PermissionSynchroniser::class)->sync());

        expect($result['created'])->toBe(2)
            ->and(RowLevelSecurity::bypass(static fn (): int => DB::table('permissions')
                ->whereIn('name', ['sales.invoices.issue', 'sales.invoices.cancel'])
                ->where('is_sensitive', true)
                ->count()))->toBe(2);
    });
});

describe('issuing and cancelling', function (): void {
    it('lets an accountant do both', function (): void {
        $accountant = invoiceMemberWithRole('accountant', 'inv-issue-acct@acme.test');

        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        expect($accountant->can('issue', $this->invoice->refresh()))->toBeFalse()
            // Refused above only because the invoice is no longer a draft — the advisory state check. The
            // capability itself is held, which the cancellation below demonstrates.
            ->and($accountant->can('cancel', $issued))->toBeTrue();
    });

    it('lets an accountant issue a draft', function (): void {
        $accountant = invoiceMemberWithRole('accountant', 'inv-issue-draft@acme.test');

        expect($accountant->can('issue', $this->invoice))->toBeTrue();
    });

    it('refuses a bookkeeper both, while still allowing drafting', function (): void {
        $bookkeeper = invoiceMemberWithRole('bookkeeper', 'inv-issue-book@acme.test');

        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        expect($bookkeeper->can('update', $this->invoice->refresh()))->toBeTrue()
            ->and($bookkeeper->can('issue', $this->invoice))->toBeFalse()
            ->and($bookkeeper->can('cancel', $issued))->toBeFalse();
    });

    it('refuses a viewer both', function (): void {
        $viewer = invoiceMemberWithRole('viewer', 'inv-issue-view@acme.test');

        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        expect($viewer->can('issue', $this->invoice))->toBeFalse()
            ->and($viewer->can('cancel', $issued))->toBeFalse();
    });

    it('refuses someone holding the capability but not a member of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'inv-iss-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        // Permission and membership are different questions, and both new methods ask both.
        expect($fresh->can('issue', $this->invoice))->toBeFalse()
            ->and($fresh->can('cancel', $issued))->toBeFalse();
    });

    it('applies the state check as guidance for a client', function (): void {
        $accountant = invoiceMemberWithRole('accountant', 'inv-state@acme.test');

        // A draft cannot be cancelled and an issued invoice cannot be issued again. Asserted so the advisory
        // half of the policy is covered — a client asking these decides whether to show a button.
        expect($accountant->can('cancel', $this->invoice))->toBeFalse();

        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        expect($accountant->can('issue', $issued))->toBeFalse()
            ->and($accountant->can('cancel', $issued))->toBeTrue();
    });
});

describe('the tenant owner', function (): void {
    it('passes every policy through the Gate::before short circuit', function (): void {
        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        // Note what this proves: `issue` is allowed on an *already issued* invoice. The owner never reaches
        // `SalesInvoicePolicy`, so its status check is unreachable for them. That is why the policy's state
        // checks are advisory and the service is the enforcement.
        expect($this->owner->can('issue', $issued))->toBeTrue()
            ->and($this->owner->can('cancel', $issued))->toBeTrue();
    });

    it('is still refused by the service when the invoice cannot be issued', function (): void {
        $issued = app(SalesInvoiceService::class)->issue($this->invoice, $this->owner);

        // The trap this architecture exists to avoid, asserted rather than assumed: the gate says yes and the
        // service says no. Had the state rule lived only in the policy, an owner would have been able to issue
        // the same invoice twice.
        $exception = catchPlatformException(
            fn () => app(SalesInvoiceService::class)->issue($issued->refresh(), $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-not-a-draft');
    });

    it('is still refused by the service when the invoice cannot be cancelled', function (): void {
        // A draft: the gate allows `cancel` for an owner, and the service refuses it.
        expect($this->owner->can('cancel', $this->invoice))->toBeTrue();

        $exception = catchPlatformException(
            fn () => app(SalesInvoiceService::class)->cancel($this->invoice, 'Not issued yet', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-not-issued');
    });
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

    it('registers the invoice morph alias, and only that one', function (): void {
        // `SalesInvoice` applies `Auditable`, and the enforced morph map means an audit entry for an unmapped
        // class throws rather than storing a class name a rename would orphan. It is also what
        // `SourceDocument` feeds back through `getMorphedModel()` when an issued invoice cites its posting.
        expect(Relation::getMorphedModel(SalesInvoice::MORPH_ALIAS))->toBe(SalesInvoice::class);

        // The line's alias was removed by decision B6. A line is never audited separately and can never be a
        // source document, so registering one claimed something may point at it — and the first caller to
        // believe that claim would have been wrong to.
        expect(Relation::getMorphedModel('sales_invoice_line'))->toBeNull()
            ->and(defined(SalesInvoiceLine::class.'::MORPH_ALIAS'))->toBeFalse();
    });
});
