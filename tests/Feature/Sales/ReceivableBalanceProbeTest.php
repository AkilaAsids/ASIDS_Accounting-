<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Infrastructure\EloquentReceivableBalanceProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The receivables probe, answering for real.
 *
 * Milestone 2 wrote two rules it could not enforce — a customer who owes money cannot be archived, and one
 * named by any invoice cannot be deleted or renamed — and bound `NoReceivables`, which truthfully reported
 * that no invoice table existed. Milestone 7 moves the binding. Nothing in `CustomerService` changed.
 *
 * Two questions, and the difference between them is the substance of this file. Owing money is about the
 * present: a paid invoice is settled, a cancelled one was reversed, a draft was never owed. Being named by
 * an invoice is about the record, and every one of those still names the customer. So the same fixture
 * produces a balance of zero and `hasAnyInvoice()` of true, which is not a contradiction — it is the pair of
 * rules working as written.
 *
 * The isolation group matters more than it looks. Row level security separates tenants but not the two
 * companies inside one workspace, so without the explicit company filter a customer would be answered with
 * a sibling company's invoices — and the archive rule would refuse for a debt owed to somebody else.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->probe = app(ReceivableBalanceProbe::class);
});

/**
 * A draft for the acme customer, built through the service so its figures are real.
 */
function receivableDraft(string $unitPrice = '1000.00', ?Customer $customer = null): SalesInvoice
{
    $customer ??= test()->customer;

    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));
}

/**
 * An issued invoice for the acme customer.
 */
function probeReceivableInvoice(string $unitPrice = '1000.00', ?Customer $customer = null): SalesInvoice
{
    return app(SalesInvoiceService::class)->issue(receivableDraft($unitPrice, $customer), test()->owner);
}

describe('the binding', function (): void {
    it('is the real implementation, not the stub', function (): void {
        // The one assertion that would have caught Milestone 5 closing with the seam still unbound.
        expect(app(ReceivableBalanceProbe::class))->toBeInstanceOf(EloquentReceivableBalanceProbe::class);
    });
});

describe('what a customer owes', function (): void {
    it('is zero when they have never been invoiced', function (): void {
        expect($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('counts an issued invoice in full', function (): void {
        $invoice = probeReceivableInvoice('1000.00');

        // 1,000 with no tax, so the whole total is due.
        expect($invoice->amount_due)->toBe('1000.0000')
            ->and($this->probe->outstandingBalance($this->customer))->toBe('1000.0000');
    });

    it('counts what is left on a partially paid invoice, not its total', function (): void {
        $invoice = probeReceivableInvoice('1000.00');

        // Phase 4 territory reached the only way it can be today: `amount_paid` is pinned at zero by a
        // phase-scoped CHECK, so the constraint comes off to move it. What matters is that the probe reads
        // `amount_due` rather than recomputing `total - amount_paid` itself.
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::PartiallyPaid->value,
            'amount_paid' => '400.0000',
            'amount_due' => '600.0000',
        ]);

        expect($this->probe->outstandingBalance($this->customer))->toBe('600.0000');
    });

    it('sums several collectable invoices', function (): void {
        probeReceivableInvoice('1000.00');
        probeReceivableInvoice('250.50');
        probeReceivableInvoice('99.49');

        expect($this->probe->outstandingBalance($this->customer))->toBe('1349.9900');
    });

    it('ignores a draft, which is not yet owed', function (): void {
        receivableDraft('5000.00');

        expect($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('ignores a cancelled invoice, whose posting was reversed', function (): void {
        $invoice = probeReceivableInvoice('1000.00');
        app(SalesInvoiceService::class)->cancel($invoice, 'Customer cancelled the order', $this->owner);

        expect($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('ignores a paid invoice, which is settled', function (): void {
        $invoice = probeReceivableInvoice('1000.00');

        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::Paid->value,
            'amount_paid' => '1000.0000',
            'amount_due' => '0.0000',
        ]);

        expect($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('returns a decimal string at the ledger scale, never a float', function (): void {
        probeReceivableInvoice('1000.00');

        $balance = $this->probe->outstandingBalance($this->customer);

        // `CustomerService` compares this with `bccomp` at `Money::SCALE`, which needs a string with the
        // scale actually present — an integer 0 or a float would compare differently.
        expect($balance)->toBeString()
            ->and($balance)->toBe('1000.0000')
            ->and(substr($balance, strpos($balance, '.') + 1))->toHaveLength(Money::SCALE);
    });

    it('returns a scaled string rather than an integer when nothing is owed', function (): void {
        // The empty-set case: the driver sums to integer 0, and the contract promises a numeric string.
        expect($this->probe->outstandingBalance($this->customer))->toBeString()->toBe('0.0000');
    });
});

describe('whether a customer has ever been invoiced', function (): void {
    it('is false before any invoice exists', function (): void {
        expect($this->probe->hasAnyInvoice($this->customer))->toBeFalse();
    });

    it('is true for a draft', function (): void {
        receivableDraft();

        // Owes nothing, and is still named by a document. The two answers differ on purpose.
        expect($this->probe->hasAnyInvoice($this->customer))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('is true for an issued invoice', function (): void {
        probeReceivableInvoice();

        expect($this->probe->hasAnyInvoice($this->customer))->toBeTrue();
    });

    it('is true for a partially paid invoice', function (): void {
        $invoice = probeReceivableInvoice('1000.00');

        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::PartiallyPaid->value,
            'amount_paid' => '400.0000',
            'amount_due' => '600.0000',
        ]);

        expect($this->probe->hasAnyInvoice($this->customer))->toBeTrue();
    });

    it('is true for a paid invoice', function (): void {
        $invoice = probeReceivableInvoice('1000.00');

        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::Paid->value,
            'amount_paid' => '1000.0000',
            'amount_due' => '0.0000',
        ]);

        // Settled and still undeletable: the invoice is a statutory record that names them.
        expect($this->probe->hasAnyInvoice($this->customer))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });

    it('is true for a cancelled invoice', function (): void {
        $invoice = probeReceivableInvoice('1000.00');
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        expect($this->probe->hasAnyInvoice($this->customer))->toBeTrue()
            ->and($this->probe->outstandingBalance($this->customer))->toBe('0.0000');
    });
});

describe('company and tenant isolation', function (): void {
    it('does not count a sibling company’s invoice', function (): void {
        // Two companies in one workspace share a `tenant_id`, so row level security is satisfied by either.
        // Only the explicit company filter separates them.
        $sibling = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Acme Exports', code: 'EXPORTS'),
            $this->owner,
        );

        app(ChartTemplateService::class)->apply($sibling);
        app(FiscalCalendarService::class)->openYearContaining($sibling, CarbonImmutable::parse('2026-06-15'));

        $siblingRevenue = Account::query()->forCompany($sibling->getKey())->where('code', '4100')->firstOrFail();

        $siblingCustomer = app(CustomerService::class)->create(
            $sibling,
            new CustomerData(name: 'Silva Traders', code: 'SILVA'),
        );

        $siblingDraft = app(SalesInvoiceService::class)->createDraft($sibling, new SalesInvoiceData(
            customerId: (string) $siblingCustomer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting services',
                quantity: '1',
                unitPrice: '7500.00',
                revenueAccountId: (string) $siblingRevenue->getKey(),
            )],
        ));

        app(SalesInvoiceService::class)->issue($siblingDraft, $this->owner);

        // The acme customer owes nothing, despite an invoice for the same-named customer next door.
        expect($this->probe->outstandingBalance($this->customer))->toBe('0.0000')
            ->and($this->probe->hasAnyInvoice($this->customer))->toBeFalse()
            // And the sibling's own customer is answered correctly.
            ->and($this->probe->outstandingBalance($siblingCustomer))->toBe('7500.0000')
            ->and($this->probe->hasAnyInvoice($siblingCustomer))->toBeTrue();
    });

    it('cannot see another tenant’s invoices', function (): void {
        probeReceivableInvoice('1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        // Row level security scopes the query, so the acme invoice is not merely filtered out — it is
        // invisible from here.
        expect(SalesInvoice::query()->count())->toBe(0);

        $this->withinTenant($this->acme['tenant']);

        expect($this->probe->outstandingBalance($this->customer))->toBe('1000.0000');
    });
});

describe('the rules this activates', function (): void {
    it('refuses to archive a customer who still owes', function (): void {
        probeReceivableInvoice('1000.00');

        // Live for the first time. `CustomerService::archive()` has carried this rule since Milestone 2 and
        // could never fire it, because the stub reported a balance of zero for everybody.
        $exception = catchPlatformException(
            fn () => app(CustomerService::class)->archive($this->customer->fresh())
        );

        expect($exception->problemCode())->toBe('customer-has-outstanding-balance');
    });

    it('allows archiving once the balance is cleared', function (): void {
        $invoice = probeReceivableInvoice('1000.00');
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        // The rule is about money owed, not about having been invoiced — so a cancelled invoice releases it.
        $archived = app(CustomerService::class)->archive($this->customer->fresh());

        expect($archived->status->value)->toBe('archived');
    });

    it('refuses to change the code of an invoiced customer', function (): void {
        probeReceivableInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => app(CustomerService::class)->update($this->customer->fresh(), ['code' => 'SILVA2'])
        );

        expect($exception->problemCode())->toBe('customer-code-locked');
    });

    it('refuses to delete an invoiced customer, even after cancellation', function (): void {
        $invoice = probeReceivableInvoice('1000.00');
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        // `hasAnyInvoice()` rather than the balance: the document names them whatever became of it.
        expect(fn () => app(CustomerService::class)->delete($this->customer->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });
});
