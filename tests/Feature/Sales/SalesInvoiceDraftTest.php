<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Exceptions\InvalidInvoiceDiscount;
use Asids\Core\Sales\Domain\Exceptions\NoApplicableTaxRate;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft invoices: creation, change, deletion, and the arithmetic.
 *
 * Stage 2 of Milestone 4. The schema tests alongside this prove what PostgreSQL refuses; these prove the
 * service computes the right numbers and refuses the right requests before the database has to.
 *
 * The totals are the part worth testing hardest, and not because they are complicated. A wrong total on screen
 * gets noticed immediately. A total that is right to the rupee and wrong by a hundredth balances, posts, ties
 * in the trial balance and misstates a return — so the tests that matter most here are the ones about the last
 * decimal place and the order of operations.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->serviceRevenue = Account::query()->forCompany($this->company->getKey())->where('code', '4200')->firstOrFail();
    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
        paymentTermsDays: 30,
    ));

    app(TaxCodeService::class)->create($this->company, new TaxCodeData(
        code: 'VAT',
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: '18',
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: (string) $this->outputVat->getKey(),
    ));

    $this->invoices = app(SalesInvoiceService::class);
});

/**
 * One line. Named `invLine` to stay clear of the global helpers other Sales suites declare.
 */
function invLine(string $quantity, string $unitPrice, ?string $taxCode = null, ?string $percent = null, ?string $amount = null): SalesInvoiceLineData
{
    return new SalesInvoiceLineData(
        description: 'Consulting services',
        quantity: $quantity,
        unitPrice: $unitPrice,
        revenueAccountId: (string) test()->revenue->getKey(),
        taxCode: $taxCode,
        discountPercent: $percent,
        discountAmount: $amount,
    );
}

/**
 * @param  list<SalesInvoiceLineData>  $lines
 */
function draftFor(array $lines, ?string $headerDiscount = null, string $invoiceDate = '2026-06-15'): SalesInvoice
{
    return test()->invoices->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($invoiceDate),
        lines: $lines,
        discountAmount: $headerDiscount,
    ), (string) test()->owner->getKey());
}

describe('creating a draft', function (): void {
    it('creates a draft with no number', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        // Gapless numbering is reserved inside the Milestone 5 issuing transaction, so an abandoned draft
        // consumes none.
        expect($invoice->status)->toBe(SalesInvoiceStatus::Draft)
            ->and($invoice->number)->toBeNull()
            ->and($invoice->issued_at)->toBeNull()
            ->and($invoice->journal_entry_id)->toBeNull();
    });

    it('derives the due date from the customer’s payment terms', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')], invoiceDate: '2026-06-15');

        // 30-day terms. The terms are exactly what the customer record exists to hold, so a caller should not
        // have to compute this.
        expect($invoice->due_date->toDateString())->toBe('2026-07-15');
    });

    it('accepts an explicit due date', function (): void {
        $invoice = $this->invoices->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [invLine('1', '1000.00')],
            dueDate: CarbonImmutable::parse('2026-08-01'),
        ));

        expect($invoice->due_date->toDateString())->toBe('2026-08-01');
    });

    it('uses the company’s base currency', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        expect($invoice->currency_code)->toBe($this->company->base_currency_code)
            ->and($invoice->exchange_rate)->toBeNull();
    });

    it('records who created it', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        expect($invoice->created_by_id)->toBe($this->owner->getKey());
    });

    it('numbers the lines in submission order', function (): void {
        $invoice = draftFor([invLine('1', '100.00'), invLine('2', '200.00'), invLine('3', '300.00')]);

        // An invoice that reprints in a different order from the one it was typed in is a different document
        // to the person reading it.
        expect($invoice->lines->pluck('line_number')->all())->toBe([1, 2, 3]);
    });

    it('refuses an invoice with no lines', function (): void {
        expect(fn () => draftFor([]))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('the arithmetic', function (): void {
    it('computes an untaxed line', function (): void {
        $invoice = draftFor([invLine('2', '500.00')]);

        expect($invoice->subtotal)->toBe('1000.0000')
            ->and($invoice->tax_total)->toBe('0.0000')
            ->and($invoice->total)->toBe('1000.0000')
            ->and($invoice->amount_due)->toBe('1000.0000');
    });

    it('computes tax on a taxed line', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT')]);

        expect($invoice->subtotal)->toBe('1000.0000')
            ->and($invoice->tax_total)->toBe('180.0000')
            ->and($invoice->total)->toBe('1180.0000');
    });

    it('snapshots the rate onto the line', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT')]);

        // Not a join. An invoice issued at 18% must still read 18% after the code's rate changes.
        expect($invoice->lines->first()->tax_rate)->toBe('18.0000')
            ->and($invoice->lines->first()->tax_code_id)->not->toBeNull();
    });

    it('sums many lines at mixed rates', function (): void {
        $invoice = draftFor([
            invLine('1', '1000.00', taxCode: 'VAT'),
            invLine('1', '500.00'),
        ]);

        expect($invoice->subtotal)->toBe('1500.0000')
            ->and($invoice->tax_total)->toBe('180.0000')
            ->and($invoice->total)->toBe('1680.0000');
    });

    it('holds the line invariant that total equals subtotal plus tax', function (): void {
        $invoice = draftFor([invLine('3', '333.33', taxCode: 'VAT')]);

        $line = $invoice->lines->first();

        expect($line->line_total)->toBe(bcadd($line->line_subtotal, $line->tax_amount, 4));
    });

    it('makes the invoice total the sum of its lines', function (): void {
        $invoice = draftFor([
            invLine('1', '333.33', taxCode: 'VAT'),
            invLine('1', '666.67', taxCode: 'VAT'),
            invLine('7', '3.3333', taxCode: 'VAT'),
        ]);

        $lineSum = $invoice->lines->reduce(
            static fn (string $carry, SalesInvoiceLine $line): string => bcadd($carry, $line->line_total, 4),
            '0.0000',
        );

        // The property that makes a printed invoice add up: a reader summing the total column must reach the
        // figure shown.
        expect($invoice->total)->toBe($lineSum);
    });

    it('rounds tax per line rather than on the sum', function (): void {
        // Three lines whose individual taxes each need rounding. Rounding the sum instead would produce a
        // different figure from the one a reader gets by adding the tax column.
        $invoice = draftFor([
            invLine('1', '33.33', taxCode: 'VAT'),
            invLine('1', '33.33', taxCode: 'VAT'),
            invLine('1', '33.33', taxCode: 'VAT'),
        ]);

        $taxSum = $invoice->lines->reduce(
            static fn (string $carry, SalesInvoiceLine $line): string => bcadd($carry, $line->tax_amount, 4),
            '0.0000',
        );

        expect($invoice->tax_total)->toBe($taxSum);
    });

    it('handles a negative line as a correction', function (): void {
        $invoice = draftFor([invLine('2', '1000.00'), invLine('-1', '250.00')]);

        expect($invoice->subtotal)->toBe('1750.0000')
            ->and($invoice->total)->toBe('1750.0000');
    });

    it('refuses an invoice whose total would be negative', function (): void {
        // A negative document is a credit note, not an invoice with a minus sign.
        expect(fn () => draftFor([invLine('1', '100.00'), invLine('-2', '200.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('allows a zero total', function (): void {
        // Approved decision B5: a draft under construction or fully discounted may total zero. The
        // positive-total rule belongs to issuing.
        $invoice = draftFor([invLine('1', '1000.00', percent: '100')]);

        expect($invoice->total)->toBe('0.0000');
    });

    it('refuses a zero quantity', function (): void {
        expect(fn () => draftFor([invLine('0', '1000.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a non-numeric quantity', function (): void {
        expect(fn () => draftFor([invLine('two', '1000.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('discounts', function (): void {
    it('applies a line percentage discount', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', percent: '10')]);

        expect($invoice->subtotal)->toBe('900.0000')
            ->and($invoice->discount_total)->toBe('100.0000');
    });

    it('applies a line fixed discount', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', amount: '150.00')]);

        expect($invoice->subtotal)->toBe('850.0000')
            ->and($invoice->discount_total)->toBe('150.0000');
    });

    it('charges tax on the discounted amount, not the gross', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT', percent: '10')]);

        // Tax is charged on what the customer actually pays. Computing it on the gross would overstate the
        // liability with every figure on the document internally consistent — wrong and invisible.
        expect($invoice->subtotal)->toBe('900.0000')
            ->and($invoice->tax_total)->toBe('162.0000')
            ->and($invoice->total)->toBe('1062.0000');
    });

    it('refuses both discount forms on one line', function (): void {
        expect(fn () => draftFor([invLine('1', '1000.00', percent: '10', amount: '50.00')]))
            ->toThrow(InvalidInvoiceDiscount::class);
    });

    it('refuses a discount larger than the line', function (): void {
        expect(fn () => draftFor([invLine('1', '1000.00', amount: '1500.00')]))
            ->toThrow(InvalidInvoiceDiscount::class);
    });

    it('refuses a percentage above one hundred', function (): void {
        expect(fn () => draftFor([invLine('1', '1000.00', percent: '101')]))
            ->toThrow(InvalidInvoiceDiscount::class);
    });

    it('allocates a header discount across lines in proportion', function (): void {
        $invoice = draftFor([invLine('1', '600.00'), invLine('1', '400.00')], headerDiscount: '100.00');

        // 60/40 split of a 100 discount.
        expect($invoice->lines->pluck('line_subtotal')->all())->toBe(['540.0000', '360.0000'])
            ->and($invoice->subtotal)->toBe('900.0000')
            ->and($invoice->discount_total)->toBe('100.0000');
    });

    it('allocates a header discount without losing or inventing a cent', function (): void {
        // 100 across three equal lines is 33.333… each. The largest-remainder method puts the shortfall
        // somewhere deterministic; what must never happen is the parts failing to sum to the whole.
        $invoice = draftFor(
            [invLine('1', '100.00'), invLine('1', '100.00'), invLine('1', '100.00')],
            headerDiscount: '100.00',
        );

        $allocated = bcsub('300.0000', $invoice->subtotal, 4);

        expect($allocated)->toBe('100.0000')
            ->and($invoice->discount_total)->toBe('100.0000');
    });

    it('taxes each line on its share of the header discount', function (): void {
        $invoice = draftFor(
            [invLine('1', '600.00', taxCode: 'VAT'), invLine('1', '400.00', taxCode: 'VAT')],
            headerDiscount: '100.00',
        );

        // 540 and 360 at 18% => 97.20 and 64.80.
        expect($invoice->lines->pluck('tax_amount')->all())->toBe(['97.2000', '64.8000'])
            ->and($invoice->tax_total)->toBe('162.0000');
    });

    it('refuses a header discount larger than the invoice', function (): void {
        expect(fn () => draftFor([invLine('1', '100.00')], headerDiscount: '200.00'))
            ->toThrow(InvalidInvoiceDiscount::class);
    });

    it('refuses a header discount when a line is negative', function (): void {
        // `Money::allocate()` refuses negative weights, and rightly: there is no defensible share for a credit
        // line. Refused rather than worked around, because a workaround invents semantics nobody asked for.
        expect(fn () => draftFor([invLine('2', '1000.00'), invLine('-1', '100.00')], headerDiscount: '50.00'))
            ->toThrow(InvalidInvoiceDiscount::class);
    });

    it('combines a line discount with a header discount', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', percent: '10')], headerDiscount: '90.00');

        // 1000 less 10% is 900; less the 90 header discount is 810. `discount_total` reports both.
        expect($invoice->subtotal)->toBe('810.0000')
            ->and($invoice->discount_total)->toBe('190.0000');
    });
});

describe('tax resolution', function (): void {
    it('resolves the rate that applied on the invoice date', function (): void {
        app(TaxCodeService::class)->endRange(
            TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->firstOrFail(),
            CarbonImmutable::parse('2026-06-30'),
        );
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-07-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
        ));

        $june = draftFor([invLine('1', '1000.00', taxCode: 'VAT')], invoiceDate: '2026-06-15');
        $july = draftFor([invLine('1', '1000.00', taxCode: 'VAT')], invoiceDate: '2026-07-15');

        // The whole reason a line names a code rather than an id: the invoice date decides which rate applies.
        expect($june->tax_total)->toBe('180.0000')
            ->and($july->tax_total)->toBe('200.0000');
    });

    it('refuses a line naming a code with no rate for the date', function (): void {
        expect(fn () => draftFor([invLine('1', '1000.00', taxCode: 'VAT')], invoiceDate: '2025-06-15'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('refuses a line naming a code that does not exist', function (): void {
        expect(fn () => draftFor([invLine('1', '1000.00', taxCode: 'NOPE')]))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('leaves an untaxed line with no code and a zero rate', function (): void {
        $line = draftFor([invLine('1', '1000.00')])->lines->first();

        expect($line->tax_code_id)->toBeNull()
            ->and($line->tax_rate)->toBe('0.0000')
            ->and($line->tax_amount)->toBe('0.0000');
    });
});

describe('validation and isolation', function (): void {
    it('refuses a customer from another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreign = app(CustomerService::class)->create($second, new CustomerData(name: 'Other', code: 'OTHER'));

        // Both companies share a tenant, so row level security is satisfied by either one's customers. Only the
        // explicit company comparison stops an invoice citing its sibling's.
        expect(fn () => $this->invoices->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $foreign->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [invLine('1', '1000.00')],
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses an archived customer for a new invoice', function (): void {
        app(CustomerService::class)->archive($this->customer);

        expect(fn () => draftFor([invLine('1', '1000.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a revenue account that is not income', function (): void {
        // Point a sales line at an expense account and the invoice still balances while the profit and loss
        // account is wrong in two places at once.
        $expense = Account::query()->forCompany($this->company->getKey())->where('code', '6200')->firstOrFail();

        expect(fn () => draftFor([new SalesInvoiceLineData(
            description: 'Wrong account',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) $expense->getKey(),
        )]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a non-postable header account', function (): void {
        $header = Account::query()->forCompany($this->company->getKey())->where('code', '4000')->firstOrFail();

        expect(fn () => draftFor([new SalesInvoiceLineData(
            description: 'Header account',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) $header->getKey(),
        )]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a revenue account from another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $foreign = Account::query()->forCompany((string) $second->getKey())->where('code', '4100')->firstOrFail();

        expect(fn () => draftFor([new SalesInvoiceLineData(
            description: 'Foreign account',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) $foreign->getKey(),
        )]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a due date before the invoice date', function (): void {
        expect(fn () => $this->invoices->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [invLine('1', '1000.00')],
            dueDate: CarbonImmutable::parse('2026-05-01'),
        )))->toThrow(BusinessRuleViolation::class);
    });
});

describe('updating a draft', function (): void {
    it('replaces every line wholesale', function (): void {
        $invoice = draftFor([invLine('1', '100.00'), invLine('1', '200.00')]);

        $this->invoices->updateDraft($invoice, ['lines' => [invLine('1', '500.00')]]);

        // An invoice is a document, not a collection that accretes. Matching submitted rows against stored ones
        // by position is how a reordered line silently becomes an edit of a different account.
        expect($invoice->fresh()->lines)->toHaveCount(1)
            ->and($invoice->fresh()->total)->toBe('500.0000');
    });

    it('changes the reference and notes', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        $this->invoices->updateDraft($invoice, ['reference' => 'PO-99999', 'notes' => 'Agreed by phone']);

        expect($invoice->fresh()->reference)->toBe('PO-99999')
            ->and($invoice->fresh()->notes)->toBe('Agreed by phone');
    });

    it('leaves the lines alone when they are not supplied', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT')]);

        $this->invoices->updateDraft($invoice, ['reference' => 'PO-11111']);

        expect($invoice->fresh()->lines)->toHaveCount(1)
            ->and($invoice->fresh()->total)->toBe('1180.0000');
    });

    it('re-resolves the rate when the invoice date moves', function (): void {
        $codes = app(TaxCodeService::class);
        $codes->endRange(
            TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->firstOrFail(),
            CarbonImmutable::parse('2026-06-30'),
        );
        $codes->create($this->company, new TaxCodeData(
            code: 'VAT', name: 'VAT', taxType: TaxType::Vat, rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-07-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
        ));

        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT')], invoiceDate: '2026-06-15');

        $this->invoices->updateDraft($invoice, ['invoice_date' => '2026-07-15', 'due_date' => '2026-08-15']);

        // Totals are recomputed even though no line was touched, because a changed date can change the rate.
        expect($invoice->fresh()->tax_total)->toBe('200.0000');
    });

    it('clears a header discount when null is passed explicitly', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')], headerDiscount: '100.00');
        expect($invoice->subtotal)->toBe('900.0000');

        // The omitted-versus-null distinction, and why `updateDraft` takes an array. Passing null clears the
        // discount; omitting the key would leave it, and it would be permanent once set.
        $this->invoices->updateDraft($invoice, ['discount_amount' => null]);

        expect($invoice->fresh()->subtotal)->toBe('1000.0000')
            ->and($invoice->fresh()->discount_total)->toBe('0.0000');
    });

    it('keeps a header discount when the key is omitted', function (): void {
        $invoice = draftFor([invLine('1', '600.00'), invLine('1', '400.00')], headerDiscount: '100.00');

        $this->invoices->updateDraft($invoice, ['reference' => 'PO-22222']);

        expect($invoice->fresh()->subtotal)->toBe('900.0000')
            ->and($invoice->fresh()->discount_total)->toBe('100.0000');
    });

    it('clears the branch when null is passed explicitly', function (): void {
        $branch = DB::table('branches')->where('company_id', $this->company->getKey())->first();
        $invoice = $this->invoices->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [invLine('1', '1000.00')],
            branchId: (string) $branch->id,
        ));
        expect($invoice->branch_id)->not->toBeNull();

        $this->invoices->updateDraft($invoice, ['branch_id' => null]);

        expect($invoice->fresh()->branch_id)->toBeNull();
    });

    it('changes the customer', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);
        $other = app(CustomerService::class)->create($this->company, new CustomerData(name: 'Perera', code: 'PERERA'));

        $this->invoices->updateDraft($invoice, ['customer_id' => (string) $other->getKey()]);

        expect($invoice->fresh()->customer_id)->toBe($other->getKey());
    });

    it('refuses a customer from another company on update', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreign = app(CustomerService::class)->create($second, new CustomerData(name: 'Other', code: 'OTHER'));

        expect(fn () => $this->invoices->updateDraft($invoice, ['customer_id' => (string) $foreign->getKey()]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses to update an invoice that is not a draft', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        // Forced past the service, which is the only way to reach a non-draft in Milestone 4. Milestone 5 adds
        // the trigger that makes this impossible rather than merely refused.
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => 'INV-0001',
            'issued_at' => now(),
        ]);

        expect(fn () => $this->invoices->updateDraft($invoice->fresh(), ['reference' => 'nope']))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('deleting a draft', function (): void {
    it('removes the invoice and its lines outright', function (): void {
        $invoice = draftFor([invLine('1', '1000.00'), invLine('1', '500.00')]);
        $id = $invoice->getKey();

        $this->invoices->deleteDraft($invoice);

        // Hard deletion, per ADR 0007 decision B2: a never-issued draft is not an accounting document, so a
        // tombstone would imply otherwise. The lines go by cascade.
        expect(SalesInvoice::query()->find($id))->toBeNull()
            ->and(DB::table('sales_invoices')->where('id', $id)->count())->toBe(0)
            ->and(DB::table('sales_invoice_lines')->where('sales_invoice_id', $id)->count())->toBe(0);
    });

    it('refuses to delete an invoice that is not a draft', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => 'INV-0001',
            'issued_at' => now(),
        ]);

        expect(fn () => $this->invoices->deleteDraft($invoice->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('derived state', function (): void {
    it('never reports a draft as overdue', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')], invoiceDate: '2026-01-01');

        // Derived, never stored. A draft is not owed, whatever its due date says.
        expect($invoice->isOverdue(CarbonImmutable::parse('2027-01-01')))->toBeFalse();
    });

    it('reports an issued invoice past its due date as overdue', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')], invoiceDate: '2026-01-01');
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => SalesInvoiceStatus::Issued->value,
            'number' => 'INV-0001',
            'issued_at' => now(),
        ]);

        expect($invoice->fresh()->isOverdue(CarbonImmutable::parse('2026-06-01')))->toBeTrue()
            ->and($invoice->fresh()->isOverdue(CarbonImmutable::parse('2026-01-15')))->toBeFalse();
    });
});

describe('the audit trail', function (): void {
    it('records the invoice creation with its total', function (): void {
        $invoice = draftFor([invLine('1', '1000.00', taxCode: 'VAT')]);

        $trail = (string) json_encode(
            AuditLog::query()
                ->where('auditable_type', SalesInvoice::MORPH_ALIAS)
                ->where('auditable_id', $invoice->getKey())
                ->get()
                ->pluck('new_values'),
        );

        expect($trail)->toContain('1180.0000');
    });

    it('records the old and new totals when lines change', function (): void {
        $invoice = draftFor([invLine('1', '1000.00')]);

        $this->invoices->updateDraft($invoice, ['lines' => [invLine('1', '2000.00')]]);

        $entry = AuditLog::query()
            ->where('auditable_type', SalesInvoice::MORPH_ALIAS)
            ->where('auditable_id', $invoice->getKey())
            ->get()
            ->first(fn (AuditLog $log): bool => $log->old_values !== null && array_key_exists('total', $log->old_values));

        // The values, not the key. A test asserting only that `total` appears would pass while the trail
        // recorded the wrong figures.
        expect($entry)->not->toBeNull()
            ->and((string) json_encode($entry->old_values))->toContain('1000.0000')
            ->and((string) json_encode($entry->new_values))->toContain('2000.0000');
    });

    it('does not audit the lines separately', function (): void {
        draftFor([invLine('1', '100.00'), invLine('1', '200.00'), invLine('1', '300.00')]);

        // Lines have no life of their own — auditing them as well would turn a three-line edit into four
        // unrelated events, and the invoice's own entries already record the document changing.
        //
        // Both spellings are checked because Stage 5 removed the line's morph alias (decision B6). Without a
        // map an audit entry would store the class name, so asserting only the alias would pass vacuously.
        expect(AuditLog::query()->where('auditable_type', 'sales_invoice_line')->count())->toBe(0)
            ->and(AuditLog::query()->where('auditable_type', SalesInvoiceLine::class)->count())->toBe(0);
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s invoices from raw SQL', function (): void {
        draftFor([invLine('1', '1000.00')]);

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('sales_invoices')->count())->toBe(0)
            ->and(DB::table('sales_invoice_lines')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('sales_invoices'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
