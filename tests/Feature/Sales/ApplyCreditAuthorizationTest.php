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
use Asids\Core\Sales\Application\DTOs\ApplyCreditData;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Who may apply held credit — ADR 0016 §F. The permission, its role grants, the policy, and the boundaries.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API ADR 0016 §F pins down:
 *
 *   - `sales.receipts.apply-credit`, a NEW accountant-only capability, sensitive, sortOrder 120, DISTINCT from
 *     `sales.receipts.manage` (100) and `sales.receipts.cancel` (110) — the same split ADR 0015 §D made for
 *     cancel, because applying credit moves money and posts to the ledger as its own action.
 *   - Granted to the accountant template (and inherited by administrator), never the bookkeeper or viewer.
 *   - `CustomerReceiptPolicy::applyCredit(User, CustomerReceipt): bool` = permission AND company access, both
 *     required, matching `cancel()`. Advisory only — `ReceiptService::applyCredit()` is the enforcement
 *     boundary — so the owner passes the gate yet the service still enforces state.
 *   - Acquired by an existing workspace on `PermissionSynchroniser::sync()` (ADR 0003), no migration.
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Catalogue/role tests fail because `sales.receipts.apply-credit` is not defined; policy tests fail because
 * `CustomerReceiptPolicy::applyCredit()` does not exist (Gate denies an unknown ability for a non-owner); the
 * service-boundary and RLS tests fail because `ReceiptService::applyCredit()` does not exist. The policy
 * SUBJECT is a fully-allocated receipt built through the shipped `record()`, so those tests fail on the
 * missing permission/policy, not on setup.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = applyAuthAccount('4100');
    $this->bank = applyAuthAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function applyAuthAccount(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

function applyAuthInvoice(string $unitPrice, ?string $customerId = null): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * A fully-allocated receipt — a valid policy subject that records through the shipped path today, so the
 * policy tests fail only on the absent permission/policy rather than on setup.
 */
function applyAuthReceipt(): CustomerReceipt
{
    $invoice = applyAuthInvoice('1000.00');

    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse('2026-06-20'),
        amount: '1000.00',
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF',
        allocations: [new ReceiptAllocationData(salesInvoiceId: (string) $invoice->getKey(), amount: '1000.00')],
    ), test()->owner);
}

/**
 * A receipt holding real credit, for the load-bearing "actually applies" and RLS tests.
 */
function applyAuthRemainderReceipt(): CustomerReceipt
{
    $sacrificial = applyAuthInvoice('1000.00');

    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse('2026-06-20'),
        amount: '1000.00',
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF',
        allocations: [new ReceiptAllocationData(salesInvoiceId: (string) $sacrificial->getKey(), amount: '700.00')],
    ), test()->owner); // remainder 300 held
}

function applyAuthMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

describe('the permission catalogue', function (): void {
    it('declares sales.receipts.apply-credit as a distinct, sensitive capability', function (): void {
        // AC — a third receipt capability, separate from manage and cancel.
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        expect($definitions)->toHaveKey('sales.receipts.apply-credit')
            ->and($definitions)->toHaveKey('sales.receipts.manage')
            ->and($definitions)->toHaveKey('sales.receipts.cancel')
            // Distinct capabilities, none riding on another.
            ->and('sales.receipts.apply-credit')->not->toBe('sales.receipts.manage')
            ->and('sales.receipts.apply-credit')->not->toBe('sales.receipts.cancel')
            // Sensitive: it moves money and posts a reclassification JV.
            ->and($definitions['sales.receipts.apply-credit']->sensitive)->toBeTrue();
    });

    it('orders it after manage and cancel', function (): void {
        // §F sortOrder 120, following the manage(100)/cancel(110) sequence.
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        expect($definitions['sales.receipts.apply-credit']->sortOrder)
            ->toBeGreaterThan($definitions['sales.receipts.cancel']->sortOrder);
    });
});

describe('role grants', function (): void {
    it('grants apply-credit to exactly the administrator and accountant', function (): void {
        // §F — accountant-only, matching every sales money-mover; administrator inherits all grantable (ADR 0003).
        $holders = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('sales.receipts.apply-credit', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        expect($holders)->toBe(['administrator', 'accountant']);
    });

    it('does not grant apply-credit to the bookkeeper or viewer', function (): void {
        $bookkeeper = collect(RoleTemplate::all())->firstOrFail(static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper');
        $viewer = collect(RoleTemplate::all())->firstOrFail(static fn (RoleTemplate $t): bool => $t->name === 'viewer');

        expect($bookkeeper->permissions)->not->toContain('sales.receipts.apply-credit')
            ->and($viewer->permissions)->not->toContain('sales.receipts.apply-credit');
    });
});

describe('reaching an existing workspace', function (): void {
    it('synchronises apply-credit into a workspace that predates it', function (): void {
        // §F / ADR 0003 — a code-defined permission an existing workspace picks up on sync, no migration.
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->where('name', 'sales.receipts.apply-credit')->delete();
        });

        RowLevelSecurity::bypass(static fn (): array => app(PermissionSynchroniser::class)->sync());

        expect(RowLevelSecurity::bypass(static fn (): bool => DB::table('permissions')
            ->where('name', 'sales.receipts.apply-credit')
            ->where('is_sensitive', true)
            ->exists()))->toBeTrue();
    });
});

describe('the policy', function (): void {
    it('lets an accountant apply credit through the gate and the service', function (): void {
        // §F — the capability is genuinely load-bearing: the accountant holds it, the policy passes, and the
        // operation actually runs.
        $accountant = applyAuthMemberWithRole('accountant', 'apply-acct@acme.test');

        expect($accountant->can('sales.receipts.apply-credit'))->toBeTrue();

        $receipt = applyAuthRemainderReceipt();
        $target = applyAuthInvoice('300.00');

        expect($accountant->can('applyCredit', $receipt))->toBeTrue();

        app(ReceiptService::class)->applyCredit($this->company, new ApplyCreditData(
            salesInvoiceId: (string) $target->getKey(),
            amount: '300.00',
            sourceReceiptId: (string) $receipt->getKey(),
        ), $accountant);

        expect($target->refresh()->status)->toBe(SalesInvoiceStatus::Paid);
    });

    it('requires apply-credit specifically, not manage or cancel', function (): void {
        // §F — the policy's applyCredit() must check the apply-credit capability, never let it ride on
        // manage or cancel. The permission is removed BEFORE the accountant is created, so no stale cache.
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->where('name', 'sales.receipts.apply-credit')->delete();
        });

        $accountant = applyAuthMemberWithRole('accountant', 'apply-manage-only@acme.test');
        $receipt = applyAuthReceipt();

        expect($accountant->can('sales.receipts.manage'))->toBeTrue()
            ->and($accountant->can('sales.receipts.cancel'))->toBeTrue()
            ->and($accountant->can('sales.receipts.apply-credit'))->toBeFalse()
            ->and($accountant->can('applyCredit', $receipt))->toBeFalse();
    });

    it('lets the tenant owner pass the gate yet the service still enforces the rules', function (): void {
        // §F — Gate::before short-circuits the policy for the owner, so ReceiptService stays the boundary.
        $receipt = applyAuthReceipt();
        $target = applyAuthInvoice('300.00');

        expect($this->owner->can('applyCredit', $receipt))->toBeTrue();

        // The service still refuses: this customer holds no credit at all.
        $exception = catchPlatformException(fn () => app(ReceiptService::class)->applyCredit($this->company, new ApplyCreditData(
            salesInvoiceId: (string) $target->getKey(),
            amount: '100.00',
        ), $this->owner));

        expect($exception->problemCode())->toBe('receipt-insufficient-credit');
    });
});

describe('membership and permission are different questions', function (): void {
    it('refuses a member of a sibling company in the same workspace', function (): void {
        // §F — company access is a distinct question from permission. RLS cannot make this distinction (both
        // companies share a tenant); only the policy's explicit canAccessCompany() check does.
        $receipt = applyAuthReceipt();

        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'apply-sib@acme.test']);
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(MembershipService::class)->grant($second, $user, $this->owner);
        $fresh = RowLevelSecurity::bypass(static fn () => $user->fresh());

        expect($fresh->can('applyCredit', $receipt))->toBeFalse();
    });
});

describe('tenant isolation', function (): void {
    it('cannot apply credit from another tenant', function (): void {
        // NFR RLS scope — a second tenant cannot drive this company's credit; RLS hides the target invoice and
        // the held credit from the service's own re-read. Mirrors CancelReceiptTest's tenant-isolation guard.
        applyAuthRemainderReceipt();
        $target = applyAuthInvoice('300.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(fn () => app(ReceiptService::class)->applyCredit($this->company, new ApplyCreditData(
            salesInvoiceId: (string) $target->getKey(),
            amount: '300.00',
        ), $other['owner']))->toThrow(ModelNotFoundException::class);

        $this->withinTenant($this->acme['tenant']);

        // Nothing moved.
        expect($target->refresh()->amount_paid)->toBe('0.0000')
            ->and(DB::table('credit_applications')->count())->toBe(0);
    });
});
