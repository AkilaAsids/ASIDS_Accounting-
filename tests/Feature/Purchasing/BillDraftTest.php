<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft bills: creation, change, deletion, the arithmetic, and the duplicate-supplier-number control — Stage 5
 * of Wave 7 (ADR 0019 §C).
 *
 * The payable-side mirror of `SalesInvoiceDraftTest`. The schema tests prove what PostgreSQL refuses; these
 * prove the service computes the right numbers and refuses the right requests before the database has to. The
 * one control with no sales analogue is the duplicate supplier-invoice-number guard (Gate-1 dec. 5) — the
 * classic AP double-payment risk.
 *
 * RED expectation before Stage 5 lands: `BillService`, `BillData`/`BillLineData` and `Bill` do not exist.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->rent = Account::query()->forCompany($this->company->getKey())->where('code', '6200')->firstOrFail();
    $this->inputVat = Account::query()->forCompany($this->company->getKey())->where('code', '1170')->firstOrFail();
    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(
        name: 'Silva Suppliers',
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
        inputAccountId: (string) $this->inputVat->getKey(),
    ));

    $this->bills = app(BillService::class);
});

/**
 * One line. Named `billLineData` to stay clear of the global helpers other suites declare.
 */
function billLineData(string $quantity, string $unitPrice, ?string $taxCode = null, ?string $percent = null, ?string $amount = null): BillLineData
{
    return new BillLineData(
        description: 'Office supplies',
        quantity: $quantity,
        unitPrice: $unitPrice,
        expenseAccountId: (string) test()->purchases->getKey(),
        taxCode: $taxCode,
        discountPercent: $percent,
        discountAmount: $amount,
    );
}

/**
 * @param  list<BillLineData>  $lines
 */
function billDraftFor(array $lines, ?string $headerDiscount = null, string $billDate = '2026-06-15', string $invoiceNumber = 'SUP-INV-001'): Bill
{
    return test()->bills->createDraft(test()->company, new BillData(
        supplierId: (string) test()->supplier->getKey(),
        billDate: CarbonImmutable::parse($billDate),
        supplierInvoiceNumber: $invoiceNumber,
        lines: $lines,
        discountAmount: $headerDiscount,
    ), (string) test()->owner->getKey());
}

describe('creating a draft', function (): void {
    it('creates a draft with no internal number', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')]);

        expect($bill->status)->toBe(BillStatus::Draft)
            ->and($bill->number)->toBeNull()
            ->and($bill->posted_at)->toBeNull()
            ->and($bill->journal_entry_id)->toBeNull();
    });

    it('captures the supplier’s own invoice number at draft', function (): void {
        // Required from creation — the statutory identity and the duplicate-guard key (ADR §A2, §C6).
        $bill = billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'ACME/2026/88');

        expect($bill->supplier_invoice_number)->toBe('ACME/2026/88');
    });

    it('refuses a blank supplier invoice number', function (): void {
        expect(fn () => billDraftFor([billLineData('1', '1000.00')], invoiceNumber: '   '))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('derives the due date from the supplier’s payment terms', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')], billDate: '2026-06-15');

        // 30-day terms the company *receives* from the supplier.
        expect($bill->due_date->toDateString())->toBe('2026-07-15');
    });

    it('uses the company’s base currency', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')]);

        expect($bill->currency_code)->toBe($this->company->base_currency_code)
            ->and($bill->exchange_rate)->toBeNull();
    });

    it('records who created it', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')]);

        expect($bill->created_by_id)->toBe($this->owner->getKey());
    });

    it('numbers the lines in submission order', function (): void {
        $bill = billDraftFor([billLineData('1', '100.00'), billLineData('2', '200.00'), billLineData('3', '300.00')]);

        expect($bill->lines->pluck('line_number')->all())->toBe([1, 2, 3]);
    });

    it('refuses a bill with no lines', function (): void {
        expect(fn () => billDraftFor([]))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('the arithmetic', function (): void {
    it('computes an untaxed line', function (): void {
        $bill = billDraftFor([billLineData('2', '500.00')]);

        expect($bill->subtotal)->toBe('1000.0000')
            ->and($bill->tax_total)->toBe('0.0000')
            ->and($bill->total)->toBe('1000.0000')
            ->and($bill->amount_due)->toBe('1000.0000');
    });

    it('computes input VAT on a taxed line', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT')]);

        expect($bill->subtotal)->toBe('1000.0000')
            ->and($bill->tax_total)->toBe('180.0000')
            ->and($bill->total)->toBe('1180.0000');
    });

    it('snapshots the rate onto the line via the resolver', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT')]);

        // Resolved by code and date, then snapshotted — never re-resolved (mirror ADR 0009 §B5).
        expect($bill->lines->first()->tax_rate)->toBe('18.0000')
            ->and($bill->lines->first()->tax_code_id)->not->toBeNull();
    });

    it('allows a zero total', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00', percent: '100')]);

        // A draft may total zero; the positive-total rule belongs to posting.
        expect($bill->total)->toBe('0.0000');
    });

    it('refuses a bill whose total would be negative', function (): void {
        // A negative document is a debit note, out of scope — refused at every stage ('bill-total-negative').
        expect(fn () => billDraftFor([billLineData('1', '100.00'), billLineData('-2', '200.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a zero quantity', function (): void {
        expect(fn () => billDraftFor([billLineData('0', '1000.00')]))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('discounts', function (): void {
    it('applies a line percentage discount', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00', percent: '10')]);

        expect($bill->subtotal)->toBe('900.0000')
            ->and($bill->discount_total)->toBe('100.0000');
    });

    it('charges input VAT on the discounted amount', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT', percent: '10')]);

        expect($bill->subtotal)->toBe('900.0000')
            ->and($bill->tax_total)->toBe('162.0000')
            ->and($bill->total)->toBe('1062.0000');
    });

    it('allocates a header discount across lines in proportion', function (): void {
        $bill = billDraftFor([billLineData('1', '600.00'), billLineData('1', '400.00')], headerDiscount: '100.00');

        expect($bill->lines->pluck('line_subtotal')->all())->toBe(['540.0000', '360.0000'])
            ->and($bill->subtotal)->toBe('900.0000')
            ->and($bill->discount_total)->toBe('100.0000');
    });
});

describe('tax resolution', function (): void {
    it('resolves the rate that applied on the bill date', function (): void {
        app(TaxCodeService::class)->endRange(
            TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->firstOrFail(),
            CarbonImmutable::parse('2026-06-30'),
        );
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT', name: 'VAT', taxType: TaxType::Vat, rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-07-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
            inputAccountId: (string) $this->inputVat->getKey(),
        ));

        $june = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT')], billDate: '2026-06-15', invoiceNumber: 'JUN-1');
        $july = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT')], billDate: '2026-07-15', invoiceNumber: 'JUL-1');

        // The whole reason a line names a code rather than an id: the bill date decides which rate applies.
        expect($june->tax_total)->toBe('180.0000')
            ->and($july->tax_total)->toBe('200.0000');
    });
});

describe('validation and isolation', function (): void {
    it('refuses a supplier from another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreign = app(SupplierService::class)->create($second, new SupplierData(name: 'Other', code: 'OTHER'));

        // Both companies share a tenant; only the explicit company comparison stops a bill citing its sibling's.
        expect(fn () => $this->bills->createDraft($this->company, new BillData(
            supplierId: (string) $foreign->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'X-1',
            lines: [billLineData('1', '1000.00')],
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a dormant or archived supplier for a new bill', function (): void {
        app(SupplierService::class)->archive($this->supplier);

        // `acceptsNewBills()` — existing bills unaffected, a new one refused ('supplier-not-billable', §C5).
        $exception = catchPlatformException(fn () => billDraftFor([billLineData('1', '1000.00')]));

        expect($exception->problemCode())->toBe('supplier-not-billable');
    });

    it('refuses an expense account that is not an expense', function (): void {
        // Point a bill line at an income account and the bill still balances while the P&L is wrong twice over.
        $income = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

        $exception = catchPlatformException(fn () => billDraftFor([new BillLineData(
            description: 'Wrong account',
            quantity: '1',
            unitPrice: '1000.00',
            expenseAccountId: (string) $income->getKey(),
        )]));

        expect($exception->problemCode())->toBe('expense-account-wrong-type');
    });

    it('refuses a non-postable header account', function (): void {
        $header = Account::query()->forCompany($this->company->getKey())->where('code', '6000')->firstOrFail();

        expect(fn () => billDraftFor([new BillLineData(
            description: 'Header account',
            quantity: '1',
            unitPrice: '1000.00',
            expenseAccountId: (string) $header->getKey(),
        )]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses an expense account from another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $foreign = Account::query()->forCompany((string) $second->getKey())->where('code', '5100')->firstOrFail();

        expect(fn () => billDraftFor([new BillLineData(
            description: 'Foreign account',
            quantity: '1',
            unitPrice: '1000.00',
            expenseAccountId: (string) $foreign->getKey(),
        )]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a due date before the bill date', function (): void {
        expect(fn () => $this->bills->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'DUE-1',
            lines: [billLineData('1', '1000.00')],
            dueDate: CarbonImmutable::parse('2026-05-01'),
        )))->toThrow(BusinessRuleViolation::class);
    });
});

describe('the duplicate supplier-invoice-number guard', function (): void {
    it('refuses recording the same number twice for one supplier', function (): void {
        billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/900');

        // The AP double-payment control (Gate-1 dec. 5). The pre-check names it before the index has to.
        $exception = catchPlatformException(fn () => billDraftFor([billLineData('1', '500.00')], invoiceNumber: 'INV/900'));

        expect($exception->problemCode())->toBe('bill-duplicate-supplier-number');
    });

    it('trims before comparing, so surrounding whitespace is the same number', function (): void {
        billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/901');

        $exception = catchPlatformException(fn () => billDraftFor([billLineData('1', '500.00')], invoiceNumber: '  INV/901  '));

        expect($exception->problemCode())->toBe('bill-duplicate-supplier-number');
    });

    it('allows the same number for a different supplier', function (): void {
        billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/902');

        $other = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Perera', code: 'PERERA'));

        $second = $this->bills->createDraft($this->company, new BillData(
            supplierId: (string) $other->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'INV/902',
            lines: [billLineData('1', '1000.00')],
        ));

        expect($second->supplier_invoice_number)->toBe('INV/902');
    });

    it('allows the same number for a supplier in a different company', function (): void {
        billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/903');

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $secondExpense = Account::query()->forCompany((string) $second->getKey())->where('code', '5100')->firstOrFail();
        $secondSupplier = app(SupplierService::class)->create($second, new SupplierData(name: 'Silva', code: 'SILVA'));

        $bill = $this->bills->createDraft($second, new BillData(
            supplierId: (string) $secondSupplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'INV/903',
            lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '1000.00', expenseAccountId: (string) $secondExpense->getKey())],
        ));

        expect($bill->supplier_invoice_number)->toBe('INV/903');
    });

    it('frees a number when the draft holding it is deleted', function (): void {
        $first = billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/904');
        $this->bills->deleteDraft($first);

        // A hard-deleted draft was a mistake; its number is available again (ADR §A4 — full, not partial, index).
        $again = billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'INV/904');

        expect($again->supplier_invoice_number)->toBe('INV/904');
    });
});

describe('updating a draft', function (): void {
    it('replaces every line wholesale', function (): void {
        $bill = billDraftFor([billLineData('1', '100.00'), billLineData('1', '200.00')]);

        $this->bills->updateDraft($bill, ['lines' => [billLineData('1', '500.00')]]);

        expect($bill->fresh()->lines)->toHaveCount(1)
            ->and($bill->fresh()->total)->toBe('500.0000');
    });

    it('re-resolves the rate when the bill date moves', function (): void {
        $codes = app(TaxCodeService::class);
        $codes->endRange(
            TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->firstOrFail(),
            CarbonImmutable::parse('2026-06-30'),
        );
        $codes->create($this->company, new TaxCodeData(
            code: 'VAT', name: 'VAT', taxType: TaxType::Vat, rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-07-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
            inputAccountId: (string) $this->inputVat->getKey(),
        ));

        $bill = billDraftFor([billLineData('1', '1000.00', taxCode: 'VAT')], billDate: '2026-06-15', invoiceNumber: 'UPD-1');

        $this->bills->updateDraft($bill, ['bill_date' => '2026-07-15', 'due_date' => '2026-08-15']);

        // Recomputed even though no line was touched, because a changed date can change the rate.
        expect($bill->fresh()->tax_total)->toBe('200.0000');
    });

    it('clears a header discount when null is passed, keeps it when the key is omitted', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')], headerDiscount: '100.00');
        expect($bill->subtotal)->toBe('900.0000');

        $this->bills->updateDraft($bill, ['notes' => 'unrelated']);
        expect($bill->fresh()->subtotal)->toBe('900.0000');

        $this->bills->updateDraft($bill, ['discount_amount' => null]);
        expect($bill->fresh()->subtotal)->toBe('1000.0000');
    });

    it('re-runs the duplicate check when the supplier invoice number changes', function (): void {
        billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'DUP-A');
        $second = billDraftFor([billLineData('1', '1000.00')], invoiceNumber: 'DUP-B');

        $exception = catchPlatformException(fn () => $this->bills->updateDraft($second, ['supplier_invoice_number' => 'DUP-A']));

        expect($exception->problemCode())->toBe('bill-duplicate-supplier-number');
    });

    it('refuses to update a bill that is not a draft', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')]);

        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'posted', 'number' => 'BILL-0001', 'posted_at' => now(),
        ]);

        expect(fn () => $this->bills->updateDraft($bill->fresh(), ['notes' => 'nope']))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('deleting a draft', function (): void {
    it('removes the bill and its lines outright', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00'), billLineData('1', '500.00')]);
        $id = $bill->getKey();

        $this->bills->deleteDraft($bill);

        expect(Bill::query()->find($id))->toBeNull()
            ->and(DB::table('bills')->where('id', $id)->count())->toBe(0)
            ->and(DB::table('bill_lines')->where('bill_id', $id)->count())->toBe(0);
    });

    it('refuses to delete a bill that is not a draft', function (): void {
        $bill = billDraftFor([billLineData('1', '1000.00')]);

        DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'posted', 'number' => 'BILL-0001', 'posted_at' => now(),
        ]);

        expect(fn () => $this->bills->deleteDraft($bill->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });
});
