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
 * What is owed, split by how overdue it is.
 *
 * Phase 4B. The bucket-edge group is the point of this file: ageing is arithmetic on dates, and arithmetic on
 * dates is where reports quietly go wrong. Every boundary the design locked — 0, 30, 31, 60, 61, 90, 91 — is
 * asserted individually against an explicit cutoff, so an off-by-one lands on exactly one test rather than
 * shifting a whole column by a day and looking plausible.
 *
 * The cutoff is always supplied. Nothing here depends on the clock, which is the property that makes a printed
 * report reproducible months later — and the reason `agedReceivables()` has no default for `$asOf`.
 *
 * The cross-check against `outstandingBalance()` matters more than it looks. Both reports read the same
 * invoices through the same scope, so if the buckets ever stop summing to the balance, one of the two has
 * drifted — and this suite says which.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);

    // A wide-open calendar: these invoices are dated across nearly a year so the buckets can be reached.
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->silva = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->reports = app(ReceivableReportService::class);

    // Every test ages against this date. Fixed, so no assertion depends on when the suite runs.
    $this->asOf = CarbonImmutable::parse('2026-08-17');
});

/**
 * A customer in the given company. Named distinctly — Pest helpers are global.
 */
function agedCustomer(string $code, ?Company $company = null): Customer
{
    return app(CustomerService::class)->create(
        $company ?? test()->company,
        new CustomerData(name: $code.' Ltd', code: $code),
    );
}

/**
 * An issued invoice with an explicit due date.
 *
 * `dueDate` is passed rather than derived from payment terms, because these tests are about the ageing
 * arithmetic and need the due date to land on an exact boundary.
 *
 * The invoice date defaults to the due date rather than to a fixed day: `assertDates()` refuses a due date
 * earlier than its invoice date — correctly, since that would make an invoice overdue the moment it was
 * issued — and the bucket tests need due dates spread across five months. Ageing reads `due_date` only, so
 * the invoice date is immaterial to what is being asserted.
 */
function agedInvoice(
    Customer $customer,
    string $unitPrice,
    string $dueDate,
    ?string $invoiceDate = null,
    ?Company $company = null,
    ?Account $revenue = null,
): SalesInvoice {
    $invoiceDate ??= $dueDate;
    $company ??= test()->company;

    $draft = app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: CarbonImmutable::parse($invoiceDate),
        dueDate: CarbonImmutable::parse($dueDate),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) ($revenue ?? test()->revenue)->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * A draft with an explicit due date, left unissued.
 */
function agedDraft(Customer $customer, string $unitPrice, string $dueDate): SalesInvoice
{
    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: CarbonImmutable::parse($dueDate),
        dueDate: CarbonImmutable::parse($dueDate),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));
}

/**
 * Moves an issued invoice to a payment status, lifting the phase-scoped CHECK on `amount_paid`.
 */
function agedPay(SalesInvoice $invoice, SalesInvoiceStatus $status, string $paid): void
{
    DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');

    DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
        'status' => $status->value,
        'amount_paid' => $paid,
        'amount_due' => bcsub($invoice->total, $paid, Money::SCALE),
    ]);
}

/**
 * The five buckets of the first row, as decimal strings.
 *
 * @return array<string, string>
 */
function agedBuckets(array $report, int $index = 0): array
{
    $row = $report['rows'][$index];

    return [
        'not_yet_due' => $row['not_yet_due']->toDecimalString(),
        'days_0_30' => $row['days_0_30']->toDecimalString(),
        'days_31_60' => $row['days_31_60']->toDecimalString(),
        'days_61_90' => $row['days_61_90']->toDecimalString(),
        'days_over_90' => $row['days_over_90']->toDecimalString(),
    ];
}

/**
 * The bucket that carries a non-zero figure, so a boundary test names one thing.
 */
function agedBucketWithAmount(array $report): string
{
    foreach (agedBuckets($report) as $bucket => $amount) {
        if ($amount !== '0.0000') {
            return $bucket;
        }
    }

    return 'none';
}

describe('bucket boundaries', function (): void {
    it('places an invoice due exactly on the cutoff in 0–30', function (): void {
        // 0 days overdue. The locked rule: due today is not "not yet due".
        agedInvoice($this->silva, '100.00', '2026-08-17');

        expect(agedBucketWithAmount($this->reports->agedReceivables($this->company, $this->asOf)))
            ->toBe('days_0_30');
    });

    it('places an invoice due 30 days ago in 0–30', function (string $dueDate, string $expected): void {
        agedInvoice($this->silva, '100.00', $dueDate);

        expect(agedBucketWithAmount($this->reports->agedReceivables($this->company, $this->asOf)))
            ->toBe($expected);
    })->with([
        // Every locked edge, against a cutoff of 2026-08-17.
        'exactly 30 days overdue' => ['2026-07-18', 'days_0_30'],
        'exactly 31 days overdue' => ['2026-07-17', 'days_31_60'],
        'exactly 60 days overdue' => ['2026-06-18', 'days_31_60'],
        'exactly 61 days overdue' => ['2026-06-17', 'days_61_90'],
        'exactly 90 days overdue' => ['2026-05-19', 'days_61_90'],
        'exactly 91 days overdue' => ['2026-05-18', 'days_over_90'],
    ]);

    it('places a future-dated invoice in not yet due', function (): void {
        // One day short of the cutoff: −1 day, so not late.
        agedInvoice($this->silva, '100.00', '2026-08-18');

        expect(agedBucketWithAmount($this->reports->agedReceivables($this->company, $this->asOf)))
            ->toBe('not_yet_due');
    });

    it('respects the supplied cutoff rather than today', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-17');

        // The same invoice ages differently against three cutoffs. If the report read the clock, two of these
        // would be wrong — and which two would change by the day.
        expect(agedBucketWithAmount($this->reports->agedReceivables($this->company, CarbonImmutable::parse('2026-08-16'))))
            ->toBe('not_yet_due')
            ->and(agedBucketWithAmount($this->reports->agedReceivables($this->company, CarbonImmutable::parse('2026-08-17'))))
            ->toBe('days_0_30')
            ->and(agedBucketWithAmount($this->reports->agedReceivables($this->company, CarbonImmutable::parse('2026-11-30'))))
            ->toBe('days_over_90');
    });

    it('echoes the cutoff it used', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-17');

        // So a printed report carries the date it was aged on rather than leaving a reader to guess.
        expect($this->reports->agedReceivables($this->company, $this->asOf)['as_of']->toDateString())
            ->toBe('2026-08-17');
    });
});

describe('aggregation', function (): void {
    it('is empty with zero totals when nothing is owed', function (): void {
        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        expect($report['rows'])->toBe([])
            ->and($report['totals']['total']->toDecimalString())->toBe('0.0000')
            ->and($report['totals']['not_yet_due']->toDecimalString())->toBe('0.0000')
            ->and($report['totals']['days_over_90']->toDecimalString())->toBe('0.0000')
            ->and($report['as_of']->toDateString())->toBe('2026-08-17');
    });

    it('spreads one customer’s invoices across their buckets', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-20');  // not yet due
        agedInvoice($this->silva, '200.00', '2026-08-17');  // 0
        agedInvoice($this->silva, '300.00', '2026-07-17');  // 31
        agedInvoice($this->silva, '400.00', '2026-06-17');  // 61
        agedInvoice($this->silva, '500.00', '2026-05-18');  // 91

        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        expect($report['rows'])->toHaveCount(1)
            ->and(agedBuckets($report))->toBe([
                'not_yet_due' => '100.0000',
                'days_0_30' => '200.0000',
                'days_31_60' => '300.0000',
                'days_61_90' => '400.0000',
                'days_over_90' => '500.0000',
            ])
            ->and($report['rows'][0]['total']->toDecimalString())->toBe('1500.0000');
    });

    it('adds two invoices landing in the same bucket', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-17');
        agedInvoice($this->silva, '250.50', '2026-07-20');

        expect(agedBuckets($this->reports->agedReceivables($this->company, $this->asOf))['days_0_30'])
            ->toBe('350.5000');
    });

    it('reports each customer separately', function (): void {
        $perera = agedCustomer('PERERA');

        agedInvoice($this->silva, '100.00', '2026-08-17');
        agedInvoice($perera, '900.00', '2026-05-18');

        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        expect($report['rows'])->toHaveCount(2)
            ->and((string) $report['rows'][0]['customer']->code)->toBe('PERERA')
            ->and(agedBuckets($report, 0)['days_over_90'])->toBe('900.0000')
            ->and(agedBuckets($report, 1)['days_0_30'])->toBe('100.0000');
    });

    it('uses amount_due on a partially paid invoice', function (): void {
        $invoice = agedInvoice($this->silva, '1000.00', '2026-07-17');
        agedPay($invoice, SalesInvoiceStatus::PartiallyPaid, '400.0000');

        // 600 outstanding, aged at 31 days — not the 1,000 original.
        expect(agedBuckets($this->reports->agedReceivables($this->company, $this->asOf))['days_31_60'])
            ->toBe('600.0000');
    });

    it('returns Money at the ledger scale', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-17');

        $report = $this->reports->agedReceivables($this->company, $this->asOf);
        $bucket = $report['rows'][0]['days_0_30'];
        $decimal = $bucket->toDecimalString();

        expect($bucket)->toBeInstanceOf(Money::class)
            ->and($report['totals']['total'])->toBeInstanceOf(Money::class)
            ->and(substr($decimal, strpos($decimal, '.') + 1))->toHaveLength(Money::SCALE);
    });
});

describe('which invoices count', function (): void {
    it('excludes a draft', function (): void {
        agedDraft($this->silva, '5000.00', '2026-05-18');

        expect($this->reports->agedReceivables($this->company, $this->asOf)['rows'])->toBe([]);
    });

    it('excludes a paid invoice', function (): void {
        $invoice = agedInvoice($this->silva, '1000.00', '2026-05-18');
        agedPay($invoice, SalesInvoiceStatus::Paid, $invoice->total);

        expect($this->reports->agedReceivables($this->company, $this->asOf)['rows'])->toBe([]);
    });

    it('excludes a cancelled invoice even though its amount_due is unchanged', function (): void {
        $invoice = agedInvoice($this->silva, '1000.00', '2026-05-18');
        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        // The trap: cancelling does not zero the column, so only the status filter keeps this out of the
        // 90+ bucket.
        expect(DB::table('sales_invoices')->where('id', $invoice->getKey())->value('amount_due'))
            ->toBe('1000.0000')
            ->and($this->reports->agedReceivables($this->company, $this->asOf)['rows'])->toBe([]);
    });

    it('excludes a customer whose balance has reached zero', function (): void {
        $perera = agedCustomer('PERERA');

        $settled = agedInvoice($this->silva, '1000.00', '2026-05-18');
        agedPay($settled, SalesInvoiceStatus::PartiallyPaid, '1000.0000');

        agedInvoice($perera, '750.00', '2026-08-17');

        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        expect($report['rows'])->toHaveCount(1)
            ->and((string) $report['rows'][0]['customer']->code)->toBe('PERERA');
    });
});

describe('totals and invariants', function (): void {
    it('sums each customer’s buckets to their own total', function (): void {
        $perera = agedCustomer('PERERA');

        agedInvoice($this->silva, '111.11', '2026-08-20');
        agedInvoice($this->silva, '222.22', '2026-07-17');
        agedInvoice($perera, '333.33', '2026-05-18');
        agedInvoice($perera, '444.44', '2026-08-17');

        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        foreach ($report['rows'] as $row) {
            $summed = $row['not_yet_due']
                ->plus($row['days_0_30'])
                ->plus($row['days_31_60'])
                ->plus($row['days_61_90'])
                ->plus($row['days_over_90']);

            expect($summed->toDecimalString())->toBe($row['total']->toDecimalString());
        }
    });

    it('sums the rows to the grand totals', function (): void {
        $perera = agedCustomer('PERERA');

        agedInvoice($this->silva, '111.11', '2026-08-20');
        agedInvoice($this->silva, '222.22', '2026-07-17');
        agedInvoice($perera, '333.33', '2026-05-18');

        $report = $this->reports->agedReceivables($this->company, $this->asOf);

        foreach (['not_yet_due', 'days_0_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total'] as $bucket) {
            $fromRows = array_reduce(
                $report['rows'],
                static fn (Money $carry, array $row): Money => $carry->plus($row[$bucket]),
                Money::zero($this->company->base_currency_code),
            );

            expect($report['totals'][$bucket]->toDecimalString())
                ->toBe($fromRows->toDecimalString());
        }

        expect($report['totals']['total']->toDecimalString())->toBe('666.6600');
    });

    it('agrees with the outstanding balance report', function (): void {
        $perera = agedCustomer('PERERA');
        $fonseka = agedCustomer('FONSEKA');

        agedInvoice($this->silva, '1000.00', '2026-08-20');
        agedInvoice($this->silva, '250.50', '2026-07-17');
        agedInvoice($perera, '99.49', '2026-05-18');
        agedInvoice($fonseka, '4000.00', '2026-08-17');

        // Two reports, two queries, one dataset. If the buckets ever stop summing to the balance, one of them
        // has drifted — and neither is derived from the other, so this is a real cross-check.
        $aged = $this->reports->agedReceivables($this->company, $this->asOf);

        $balance = array_reduce(
            $this->reports->outstandingBalance($this->company),
            static fn (Money $carry, array $row): Money => $carry->plus($row['outstanding']),
            Money::zero($this->company->base_currency_code),
        );

        expect($aged['totals']['total']->toDecimalString())->toBe($balance->toDecimalString())
            ->and($balance->toDecimalString())->toBe('5349.9900');
    });

    it('agrees per customer with the outstanding balance report', function (): void {
        $perera = agedCustomer('PERERA');

        agedInvoice($this->silva, '1000.00', '2026-08-20');
        agedInvoice($this->silva, '250.50', '2026-07-17');
        agedInvoice($perera, '99.49', '2026-05-18');

        $aged = [];
        foreach ($this->reports->agedReceivables($this->company, $this->asOf)['rows'] as $row) {
            $aged[(string) $row['customer']->code] = $row['total']->toDecimalString();
        }

        $balance = [];
        foreach ($this->reports->outstandingBalance($this->company) as $row) {
            $balance[(string) $row['customer']->code] = $row['outstanding']->toDecimalString();
        }

        expect($aged)->toBe($balance);
    });
});

describe('ordering', function (): void {
    it('puts the largest total first', function (): void {
        $small = agedCustomer('AAA-SMALL');
        $large = agedCustomer('ZZZ-LARGE');

        agedInvoice($small, '100.00', '2026-08-17');
        agedInvoice($large, '9000.00', '2026-05-18');

        $codes = array_map(
            static fn (array $r): string => (string) $r['customer']->code,
            $this->reports->agedReceivables($this->company, $this->asOf)['rows'],
        );

        expect($codes)->toBe(['ZZZ-LARGE', 'AAA-SMALL']);
    });

    it('breaks an equal total by customer code', function (): void {
        $b = agedCustomer('BBB');
        $a = agedCustomer('AAA');
        $c = agedCustomer('CCC');

        // Same amount, different buckets, created out of alphabetical order.
        agedInvoice($b, '500.00', '2026-08-17');
        agedInvoice($a, '500.00', '2026-05-18');
        agedInvoice($c, '500.00', '2026-07-17');

        $codes = array_map(
            static fn (array $r): string => (string) $r['customer']->code,
            $this->reports->agedReceivables($this->company, $this->asOf)['rows'],
        );

        expect($codes)->toBe(['AAA', 'BBB', 'CCC']);
    });

    it('compares totals numerically, not as strings', function (): void {
        $bigger = agedCustomer('BIGGER');
        $smaller = agedCustomer('SMALLER');

        agedInvoice($bigger, '1000.00', '2026-08-17');
        agedInvoice($smaller, '900.00', '2026-05-18');

        $codes = array_map(
            static fn (array $r): string => (string) $r['customer']->code,
            $this->reports->agedReceivables($this->company, $this->asOf)['rows'],
        );

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
        $siblingCustomer = agedCustomer('SILVA', $sibling);

        agedInvoice($siblingCustomer, '7500.00', '2026-05-18', null, $sibling, $siblingRevenue);
        agedInvoice($this->silva, '100.00', '2026-08-17');

        // Same customer code on both sides, deliberately: two companies share a tenant, so only the explicit
        // company filter separates them.
        $acme = $this->reports->agedReceivables($this->company, $this->asOf);
        $exports = $this->reports->agedReceivables($sibling, $this->asOf);

        expect($acme['totals']['total']->toDecimalString())->toBe('100.0000')
            ->and(agedBuckets($acme)['days_0_30'])->toBe('100.0000')
            ->and($exports['totals']['total']->toDecimalString())->toBe('7500.0000')
            ->and(agedBuckets($exports)['days_over_90'])->toBe('7500.0000');
    });

    it('cannot see another tenant’s invoices', function (): void {
        agedInvoice($this->silva, '100.00', '2026-08-17');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(SalesInvoice::query()->count())->toBe(0);

        $this->withinTenant($this->acme['tenant']);

        expect($this->reports->agedReceivables($this->company, $this->asOf)['rows'])->toHaveCount(1);
    });
});
