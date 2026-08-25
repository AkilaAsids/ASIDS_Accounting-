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
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptPostingMap;
use Asids\Core\Sales\Application\Services\ReceiptService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Policies\CustomerReceiptPolicy;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who may record and allocate a customer receipt, and how the module is wired — ADR 0014 §E, Gate-1 decision 6.
 *
 * Written RED, before `sales.receipts.manage`, `CustomerReceiptPolicy`, `CustomerReceipt::MORPH_ALIAS` and the
 * RLS policies on `customer_receipts` / `receipt_allocations` exist. One permission this wave, granted to the
 * accountant only — the split "record" vs. "allocate" the requirements floated (OQ-5/OQ-8) was decided against
 * in Gate 1, because record-and-allocate is atomic (Gate-1 #2), so there is exactly one action to gate.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));

    $draft = app(SalesInvoiceService::class)->createDraft($this->company, new SalesInvoiceData(
        customerId: (string) $this->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) $this->revenue->getKey(),
        )],
    ));
    $this->invoice = app(SalesInvoiceService::class)->issue($draft, $this->owner);
});

/**
 * A member of the acme company holding the given role.
 */
function receiptMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

/**
 * Records a receipt fully allocated to the suite's invoice.
 */
function recordSuiteReceipt(): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse('2026-06-20'),
        amount: '1000.00',
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF-1',
        allocations: [new ReceiptAllocationData(salesInvoiceId: (string) test()->invoice->getKey(), amount: '1000.00')],
    ), test()->owner);
}

describe('the permission catalogue', function (): void {
    it('declares sales.receipts.manage', function (): void {
        $names = array_map(
            static fn (object $definition): string => $definition->name(),
            PermissionCatalogue::all(),
        );

        expect($names)->toContain('sales.receipts.manage');
    });

    it('marks it sensitive, because it moves money and posts to the ledger', function (): void {
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        expect($definitions['sales.receipts.manage']->sensitive)->toBeTrue();
    });
});

describe('role grants', function (): void {
    it('grants sales.receipts.manage to the accountant', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'accountant',
        );

        expect($template->permissions)->toContain('sales.receipts.manage');
    });

    it('does not grant it to the bookkeeper', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );

        // The same split as invoice issuing: recording day-to-day drafts is bookkeeper work, committing money
        // to the ledger is not.
        expect($template->permissions)->not->toContain('sales.receipts.manage');
    });

    it('does not grant it to the viewer', function (): void {
        $template = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($template->permissions)->not->toContain('sales.receipts.manage');
    });

    it('grants it to exactly the templates intended', function (): void {
        $holders = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('sales.receipts.manage', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        // `administrator` inherits every tenant-grantable capability automatically (ADR 0003); `accountant` is
        // the one role this wave names explicitly.
        expect($holders)->toBe(['administrator', 'accountant']);
    });
});

describe('reaching an existing workspace', function (): void {
    it('synchronises the new capability into a workspace that predates it', function (): void {
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->where('name', 'sales.receipts.manage')->delete();
        });

        expect(RowLevelSecurity::bypass(
            static fn (): int => DB::table('permissions')->where('name', 'sales.receipts.manage')->count()
        ))->toBe(0);

        $result = RowLevelSecurity::bypass(static fn (): array => app(PermissionSynchroniser::class)->sync());

        expect($result['created'])->toBeGreaterThanOrEqual(1)
            ->and(RowLevelSecurity::bypass(static fn (): bool => DB::table('permissions')
                ->where('name', 'sales.receipts.manage')
                ->where('is_sensitive', true)
                ->exists()))->toBeTrue();
    });
});

describe('the accountant', function (): void {
    it('may record and allocate a receipt', function (): void {
        $accountant = receiptMemberWithRole('accountant', 'rcpt-acct@acme.test');

        expect($accountant->can('sales.receipts.manage'))->toBeTrue();

        // The capability is genuinely load-bearing: the accountant can actually perform the operation, not
        // merely hold a permission string nobody checks.
        $receipt = recordSuiteReceipt();

        expect($receipt->status)->toBe('posted');
    });
});

describe('the bookkeeper and the viewer', function (): void {
    it('refuses a bookkeeper the capability', function (): void {
        $bookkeeper = receiptMemberWithRole('bookkeeper', 'rcpt-book@acme.test');

        expect($bookkeeper->can('sales.receipts.manage'))->toBeFalse();
    });

    it('refuses a viewer the capability', function (): void {
        $viewer = receiptMemberWithRole('viewer', 'rcpt-view@acme.test');

        expect($viewer->can('sales.receipts.manage'))->toBeFalse();
    });
});

describe('membership and permission are different questions', function (): void {
    it('refuses someone holding the capability but not a member of the company', function (): void {
        $outsider = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'rcpt-out@acme.test']);
        $fresh = RowLevelSecurity::bypass(static fn () => $outsider->fresh());

        $receipt = recordSuiteReceipt();

        expect($fresh->can('view', $receipt))->toBeFalse();
    });

    it('refuses a member of a sibling company in the same workspace', function (): void {
        $receipt = recordSuiteReceipt();

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'rcpt-sib@acme.test']);
        $second = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->owner,
        );
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        // Row level security cannot make this distinction — both companies share a tenant — so only the
        // policy's explicit company check does.
        expect($fresh->can('view', $receipt))->toBeFalse();
    });
});

describe('provider registration', function (): void {
    it('resolves CustomerReceiptPolicy for the receipt model', function (): void {
        expect(Gate::getPolicyFor(CustomerReceipt::class))->toBeInstanceOf(CustomerReceiptPolicy::class);
    });

    it('registers the receipt morph alias', function (): void {
        // `CustomerReceipt` applies `Auditable`, and `SourceDocument::for()` needs the alias to cite the
        // receipt from its journal entry — the same wiring `SalesInvoice::MORPH_ALIAS` needed.
        expect(Relation::getMorphedModel(CustomerReceipt::MORPH_ALIAS))->toBe(CustomerReceipt::class);
    });

    it('resolves ReceiptService and ReceiptPostingMap as singletons', function (): void {
        expect(app(ReceiptService::class))->toBeInstanceOf(ReceiptService::class)
            ->and(app(ReceiptService::class))->toBe(app(ReceiptService::class))
            ->and(app(ReceiptPostingMap::class))
            ->toBe(app(ReceiptPostingMap::class));
    });
});

describe('tenant and company isolation of the tables themselves', function (): void {
    it('keeps a posted receipt invisible from another tenant', function (): void {
        $receipt = recordSuiteReceipt();

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(CustomerReceipt::query()->whereKey($receipt->getKey())->exists())->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(CustomerReceipt::query()->whereKey($receipt->getKey())->exists())->toBeTrue();
    });

    it('isolates receipt_allocations by its own policy, not transitively through the receipt', function (): void {
        // RLS is not transitive — `sales_invoice_lines` needed its own policy despite always joining through
        // `sales_invoices`, and the ADR states `receipt_allocations` needs the same. Queried directly, as the
        // existing suite does for lines, rather than through the receipt relation.
        $receipt = recordSuiteReceipt();

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(DB::table('receipt_allocations')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(DB::table('receipt_allocations')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeTrue();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('customer_receipts'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});

describe('the tenant owner', function (): void {
    it('passes the policy through the Gate::before short circuit', function (): void {
        $receipt = recordSuiteReceipt();

        expect($this->owner->can('view', $receipt))->toBeTrue();
    });
});
