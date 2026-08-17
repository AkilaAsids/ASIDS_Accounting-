<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceivableReportService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What each customer currently owes.
 *
 * The first of Milestone 7's three reports, and the foundation for the other two: aged receivables buckets
 * the same figures, and the AR reconciliation groups them by posted account. If this is wrong, all three are.
 *
 * The status group is where the value is. `amount_due` is not zeroed by cancellation — the CHECK holds it at
 * `total - amount_paid` — so a cancelled invoice still carries its full figure and is kept out only by the
 * status filter. A report that summed the column without `collectable()` would count every cancelled invoice
 * ever raised, and would look entirely plausible while doing it.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->silva = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->reports = app(ReceivableReportService::class);
});

/**
 * A customer in the acme company. Named distinctly — Pest helpers are global.
 */
function balanceCustomer(string $code, ?Company $company = null): Customer
{
    return app(CustomerService::class)->create(
        $company ?? test()->company,
        new CustomerData(name: $code.' Ltd', code: $code),
    );
}

/**
 * A draft for the given customer, built through the service so its figures are real.
 */
function balanceDraft(Customer $customer, string $unitPrice, ?Company $company = null, ?Account $revenue = null): SalesInvoice
{
    $company ??= test()->company;

    return app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) ($revenue ?? test()->revenue)->getKey(),
        )],
    ));
}

/**
 * An issued invoice for the given customer.
 */
function balanceInvoice(Customer $customer, string $unitPrice, ?Company $company = null, ?Account $revenue = null): SalesInvoice
{
    return app(SalesInvoiceService::class)->issue(
        balanceDraft($customer, $unitPrice, $company, $revenue),
        test()->owner,
    );
}

/**
 * Moves an issued invoice to a payment status, lifting the phase-scoped CHECK that pins `amount_paid`.
 */
function balancePay(SalesInvoice $invoice, SalesInvoiceStatus $status, string $paid): void
{
    DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');

    DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
        'status' => $status->value,
        'amount_paid' => $paid,
        'amount_due' => bcsub($invoice->total, $paid, Money::SCALE),
    ]);
}

/**
 * The report keyed by customer code, for readable assertions.
 *
 * @return array<string, array{outstanding: string, invoice_count: int}>
 */
function balanceByCode(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $out[(string) $row['customer']->code] = [
            'outstanding' => $row['outstanding']->toDecimalString(),
            'invoice_count' => $row['invoice_count'],
        ];
    }

    return $out;
}

describe('what each customer owes', function (): void {
    it('is empty when nothing has been invoiced', function (): void {
        expect($this->reports->outstandingBalance($this->company))->toBe([]);
    });

    it('counts an issued invoice in full', function (): void {
        balanceInvoice($this->silva, '1000.00');

        $rows = $this->reports->outstandingBalance($this->company);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['customer']->getKey())->toBe($this->silva->getKey())
            ->and($rows[0]['outstanding'])->toBeInstanceOf(Money::class)
            ->and($rows[0]['outstanding']->toDecimalString())->toBe('1000.0000')
            ->and($rows[0]['invoice_count'])->toBe(1);
    });

    it('counts what is left on a partially paid invoice, not its total', function (): void {
        $invoice = balanceInvoice($this->silva, '1000.00');
        balancePay($invoice, SalesInvoiceStatus::PartiallyPaid, '400.0000');

        $rows = $this->reports->outstandingBalance($this->company);

        expect($rows[0]['outstanding']->toDecimalString())->toBe('600.0000');
    });

    it('aggregates several invoices for one customer', function (): void {
        balanceInvoice($this->silva, '1000.00');
        balanceInvoice($this->silva, '250.50');
        balanceInvoice($this->silva, '99.49');

        $rows = $this->reports->outstandingBalance($this->company);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['outstanding']->toDecimalString())->toBe('1349.9900')
            ->and($rows[0]['invoice_count'])->toBe(3);
    });

    it('reports each customer separately', function (): void {
        $perera = balanceCustomer('PERERA');

        balanceInvoice($this->silva, '1000.00');
        balanceInvoice($perera, '2500.00');

        expect(balanceByCode($this->reports->outstandingBalance($this->company)))->toBe([
            'PERERA' => ['outstanding' => '2500.0000', 'invoice_count' => 1],
            'SILVA' => ['outstanding' => '1000.0000', 'invoice_count' => 1],
        ]);
    });

    it('returns Money at the ledger scale, not a float', function (): void {
        balanceInvoice($this->silva, '1000.00');

        $outstanding = $this->reports->outstandingBalance($this->company)[0]['outstanding'];
        $decimal = $outstanding->toDecimalString();

        expect($outstanding)->toBeInstanceOf(Money::class)
            ->and($decimal)->toBeString()
            ->and(substr($decimal, strpos($decimal, '.') + 1))->toHaveLength(Money::SCALE);
    });
});

describe('which invoices count', function (): void {
    it('excludes a draft', function (): void {
        balanceDraft($this->silva, '5000.00');

        expect($this->reports->outstandingBalance($this->company))->toBe([]);
    });

    it('excludes a paid invoice', function (): void {
        $invoice = balanceInvoice($this->silva, '1000.00');
        balancePay($invoice, SalesInvoiceStatus::Paid, $invoice->total);

        expect($this->reports->outstandingBalance($this->company))->toBe([]);
    });

    it('excludes a cancelled invoice, whose amount_due is still its full total', function (): void {
        $invoice = balanceInvoice($this->silva, '1000.00');
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        // The trap this test exists for: cancellation does not zero the column.
        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('amount_due'))
            ->toBe('1000.0000')
            ->and($this->reports->outstandingBalance($this->company))->toBe([]);
    });

    it('keeps a customer whose other invoice is still open', function (): void {
        $open = balanceInvoice($this->silva, '1000.00');
        $cancelled = balanceInvoice($this->silva, '4000.00');

        app(SalesInvoiceService::class)->cancel($cancelled, 'Duplicate', $this->owner);

        $rows = $this->reports->outstandingBalance($this->company);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['outstanding']->toDecimalString())->toBe('1000.0000')
            ->and($rows[0]['invoice_count'])->toBe(1)
            ->and($open->refresh()->status)->toBe(SalesInvoiceStatus::Issued);
    });

    it('excludes a customer whose balance has reached zero', function (): void {
        $perera = balanceCustomer('PERERA');

        $settled = balanceInvoice($this->silva, '1000.00');
        balancePay($settled, SalesInvoiceStatus::PartiallyPaid, '1000.0000');

        balanceInvoice($perera, '750.00');

        // Still collectable by status, but nothing is owed — so the row is dropped in SQL rather than
        // reported as zero.
        expect(balanceByCode($this->reports->outstandingBalance($this->company)))
            ->toBe(['PERERA' => ['outstanding' => '750.0000', 'invoice_count' => 1]]);
    });
});

describe('ordering', function (): void {
    it('puts the largest balance first', function (): void {
        $small = balanceCustomer('AAA-SMALL');
        $large = balanceCustomer('ZZZ-LARGE');

        balanceInvoice($small, '100.00');
        balanceInvoice($large, '9000.00');

        $codes = array_map(static fn (array $r): string => (string) $r['customer']->code, $this->reports->outstandingBalance($this->company));

        // Amount wins over code: the alphabetically-first customer owes least and comes last.
        expect($codes)->toBe(['ZZZ-LARGE', 'AAA-SMALL']);
    });

    it('breaks an equal balance by customer code', function (): void {
        $b = balanceCustomer('BBB');
        $a = balanceCustomer('AAA');
        $c = balanceCustomer('CCC');

        foreach ([$b, $a, $c] as $customer) {
            balanceInvoice($customer, '500.00');
        }

        $codes = array_map(static fn (array $r): string => (string) $r['customer']->code, $this->reports->outstandingBalance($this->company));

        // Three identical balances created out of order: without the tie-break the order would be whatever
        // the database returned, and the report would differ between runs.
        expect($codes)->toBe(['AAA', 'BBB', 'CCC']);
    });

    it('compares amounts numerically, not as strings', function (): void {
        $bigger = balanceCustomer('BIGGER');
        $smaller = balanceCustomer('SMALLER');

        // 900 sorts after 1000 as a string and before it as a number.
        balanceInvoice($bigger, '1000.00');
        balanceInvoice($smaller, '900.00');

        $codes = array_map(static fn (array $r): string => (string) $r['customer']->code, $this->reports->outstandingBalance($this->company));

        expect($codes)->toBe(['BIGGER', 'SMALLER']);
    });
});

describe('company and tenant isolation', function (): void {
    it('does not report a sibling company’s invoices', function (): void {
        $sibling = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Acme Exports', code: 'EXPORTS'),
            $this->owner,
        );

        app(ChartTemplateService::class)->apply($sibling);
        app(FiscalCalendarService::class)->openYearContaining($sibling, CarbonImmutable::parse('2026-06-15'));

        $siblingRevenue = Account::query()->forCompany($sibling->getKey())->where('code', '4100')->firstOrFail();
        $siblingCustomer = balanceCustomer('SILVA', $sibling);

        balanceInvoice($siblingCustomer, '7500.00', $sibling, $siblingRevenue);
        balanceInvoice($this->silva, '1000.00');

        // Two companies share a tenant, so row level security is satisfied by either — only the explicit
        // company filter separates them. Same customer code on both sides, deliberately.
        expect(balanceByCode($this->reports->outstandingBalance($this->company)))
            ->toBe(['SILVA' => ['outstanding' => '1000.0000', 'invoice_count' => 1]])
            ->and(balanceByCode($this->reports->outstandingBalance($sibling)))
            ->toBe(['SILVA' => ['outstanding' => '7500.0000', 'invoice_count' => 1]]);
    });

    it('cannot see another tenant’s invoices', function (): void {
        balanceInvoice($this->silva, '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        // Row level security makes the acme rows invisible rather than merely filtered.
        expect(SalesInvoice::query()->count())->toBe(0);

        $this->withinTenant($this->acme['tenant']);

        expect($this->reports->outstandingBalance($this->company))->toHaveCount(1);
    });
});
