<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * That the invoice factories produce rows the database and the domain both accept.
 *
 * Written because a factory shipped unexercised is a liability rather than a convenience — the lesson
 * `TaxCodeFactory` taught in Milestone 3's hardening review. Milestone 5 will build issuing and posting fixtures
 * on these two, and a factory producing rows that violate a CHECK would surface as a confusing failure inside
 * *those* tests rather than here.
 *
 * Two contracts get pinned down. Neither factory can invent a company, a customer or an account, so all three
 * must be supplied — the same rule as `CustomerFactory` and `TaxCodeFactory`, and for the same reason: a
 * conjured company has no chart of accounts, so nothing it owned could legitimately be invoiced. And the
 * defaults satisfy the money invariants, so a caller who supplies only the references gets a valid row.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->references = [
        'company_id' => $this->company->getKey(),
        'customer_id' => $this->customer->getKey(),
    ];
});

describe('the invoice factory', function (): void {
    it('produces a valid zero-total draft', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        // Zero because the totals belong to the lines. A factory inventing figures would produce a header that
        // disagreed with its lines — which the CHECKs would refuse, or worse, would not.
        expect($invoice->exists)->toBeTrue()
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Draft)
            ->and($invoice->total)->toBe('0.0000')
            ->and($invoice->amount_due)->toBe('0.0000')
            ->and($invoice->number)->toBeNull()
            ->and($invoice->issued_at)->toBeNull()
            ->and($invoice->journal_entry_id)->toBeNull();
    });

    it('inherits the tenant from the active context', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        // `BelongsToTenant` supplies `tenant_id`, which is why the factory does not — and must not, since a
        // hardcoded tenant would defeat the isolation the trait exists to enforce.
        expect($invoice->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('requires a company and a customer rather than inventing them', function (): void {
        expect(fn () => SalesInvoice::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('satisfies the money invariant the database asserts', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        // `amount_due = total - amount_paid`, enforced by CHECK. A factory that broke it would fail on insert.
        expect(bcsub($invoice->total, $invoice->amount_paid, 4))->toBe($invoice->amount_due);
    });

    it('honours the date state', function (): void {
        $invoice = SalesInvoice::factory()->on('2026-03-01', '2026-04-01')->create($this->references);

        expect($invoice->invoice_date->toDateString())->toBe('2026-03-01')
            ->and($invoice->due_date->toDateString())->toBe('2026-04-01');
    });

    it('defaults the due date to the invoice date when only one is given', function (): void {
        // Due on receipt, which the `due_date >= invoice_date` CHECK permits.
        $invoice = SalesInvoice::factory()->on('2026-03-01')->create($this->references);

        expect($invoice->due_date->toDateString())->toBe('2026-03-01');
    });

    it('produces drafts that do not collide on the number index', function (): void {
        SalesInvoice::factory()->count(3)->create($this->references);

        // Every draft has a null number, and the unique index is partial for exactly that reason — a plain
        // unique would permit one draft per company.
        expect(SalesInvoice::query()->forCompany($this->company->getKey())->count())->toBe(3)
            ->and(DB::table('sales_invoices')->whereNull('number')->count())->toBe(3);
    });
});

describe('the line factory', function (): void {
    it('produces a valid line', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        $line = SalesInvoiceLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ]);

        expect($line->exists)->toBeTrue()
            ->and($line->tenant_id)->toBe($this->acme['tenant']->getKey())
            ->and($line->line_number)->toBe(1);
    });

    it('satisfies the line total invariant', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        $line = SalesInvoiceLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ]);

        // `line_total = line_subtotal + tax_amount`, enforced by CHECK. The defaults have to satisfy it rather
        // than relying on a caller to notice.
        expect($line->line_total)->toBe(bcadd($line->line_subtotal, $line->tax_amount, 4));
    });

    it('requires an invoice, a company and a revenue account', function (): void {
        expect(fn () => SalesInvoiceLine::factory()->create())
            ->toThrow(QueryException::class);
    });

    it('keeps the costing state internally consistent', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);

        $line = SalesInvoiceLine::factory()->costing('3', '250.0000')->create([
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ]);

        expect($line->line_subtotal)->toBe('750.0000')
            ->and($line->line_total)->toBe('750.0000');
    });

    it('places lines at distinct positions', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);
        $shared = [
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ];

        SalesInvoiceLine::factory()->atPosition(1)->create($shared);
        SalesInvoiceLine::factory()->atPosition(2)->create($shared);

        // The position index is unique per invoice, so a factory that always used line 1 would collide the
        // moment a test wanted two lines.
        expect($invoice->fresh()->lines->pluck('line_number')->all())->toBe([1, 2]);
    });

    it('refuses two lines at the same position', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);
        $shared = [
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ];

        SalesInvoiceLine::factory()->atPosition(1)->create($shared);

        expect(fn () => SalesInvoiceLine::factory()->atPosition(1)->create($shared))
            ->toThrow(QueryException::class);
    });

    it('accepts a snapshotted tax rate when a code is supplied', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
        ));

        $invoice = SalesInvoice::factory()->create($this->references);

        $line = SalesInvoiceLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
            'tax_code_id' => $taxCode->getKey(),
            'tax_rate' => '18.0000',
            'tax_amount' => '180.0000',
            'line_total' => '1180.0000',
        ]);

        // The `tax_code_id IS NOT NULL OR tax_rate = 0` CHECK means a rate needs a code to attribute it to.
        expect($line->tax_rate)->toBe('18.0000')
            ->and($line->tax_code_id)->toBe($taxCode->getKey());
    });

    it('dies with its invoice', function (): void {
        $invoice = SalesInvoice::factory()->create($this->references);
        SalesInvoiceLine::factory()->create([
            'company_id' => $this->company->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'revenue_account_id' => $this->revenue->getKey(),
        ]);

        $invoice->delete();

        expect(DB::table('sales_invoice_lines')->count())->toBe(0);
    });
});
