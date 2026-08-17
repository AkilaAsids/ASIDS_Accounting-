<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Sales\Infrastructure\EloquentTaxRateUsageProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The rate-usage probe, answering for real.
 *
 * Milestone 3 wrote two rules it could not enforce — a used rate row cannot be edited, and cannot be deleted
 * — and bound `NoTaxRateUsage`, which truthfully reported that no document carrying tax existed. Milestone 7
 * moves the binding. Nothing in `TaxCodeService` changed.
 *
 * Two properties carry this file.
 *
 * **Per row, not per code.** `VAT` at 18% and `VAT` at 20% are two rows sharing a code. An invoice citing
 * the first must freeze the first and leave the second freely editable — which is the whole reason ADR 0006
 * made a rate change a new row rather than an edit. The `same code, two rows` test is the one that would
 * catch a query written against the code string.
 *
 * **A draft is not an accounting document.** `tax_code_id` lands on a line the moment a draft is saved, so
 * the naive query freezes a rate as soon as somebody starts typing. Everything else counts, cancelled
 * included: cancellation reverses the posting, it does not remove it, and both entries still cite the rate.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();

    $this->customer = app(CustomerService::class)->create(
        $this->company,
        new CustomerData(name: 'Silva Traders', code: 'SILVA'),
    );

    $this->probe = app(TaxRateUsageProbe::class);
});

/**
 * A charging tax code for the acme company. Named distinctly because Pest helpers are global.
 */
function usageTaxCode(
    string $code = 'VAT',
    string $rate = '18',
    string $from = '2026-01-01',
    ?string $to = null,
): TaxCode {
    return app(TaxCodeService::class)->create(test()->company, new TaxCodeData(
        code: $code,
        name: $code.' at '.$rate.'%',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse($from),
        effectiveTo: $to === null ? null : CarbonImmutable::parse($to),
        outputAccountId: (string) test()->outputVat->getKey(),
    ));
}

/**
 * A draft citing the given tax code by its code string, which is how a line names one.
 */
function usageDraft(TaxCode $taxCode, string $date = '2026-06-15'): SalesInvoice
{
    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($date),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: '1000.00',
            revenueAccountId: (string) test()->revenue->getKey(),
            taxCode: $taxCode->code,
        )],
    ));
}

/**
 * Moves an issued invoice to a payment status, lifting the phase-scoped CHECK that pins `amount_paid`.
 */
function usageMoveTo(SalesInvoice $invoice, SalesInvoiceStatus $status, string $paid, string $due): void
{
    DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_no_payments_until_payments_phase');

    DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
        'status' => $status->value,
        'amount_paid' => $paid,
        'amount_due' => $due,
    ]);
}

describe('the binding', function (): void {
    it('is the real implementation, not the stub', function (): void {
        // The assertion that would have caught Milestone 4 closing with this seam still unbound.
        expect(app(TaxRateUsageProbe::class))->toBeInstanceOf(EloquentTaxRateUsageProbe::class);
    });
});

describe('whether a rate row has been applied', function (): void {
    it('is false for a code nothing has ever cited', function (): void {
        expect($this->probe->hasBeenApplied(usageTaxCode()))->toBeFalse();
    });

    it('is false while the only invoice citing it is a draft', function (): void {
        $taxCode = usageTaxCode();
        $draft = usageDraft($taxCode);

        // The line already carries `tax_code_id` — the row is referenced, and still editable, because a draft
        // has no number, is not in the ledger and the customer has never seen it.
        expect(DB::table('sales_invoice_lines')->where('sales_invoice_id', $draft->getKey())
            ->value('tax_code_id'))->toBe((string) $taxCode->getKey())
            ->and($this->probe->hasBeenApplied($taxCode))->toBeFalse();
    });

    it('is true once the invoice is issued', function (): void {
        $taxCode = usageTaxCode();
        app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        expect($this->probe->hasBeenApplied($taxCode))->toBeTrue();
    });

    it('is true for a partially paid invoice', function (): void {
        $taxCode = usageTaxCode();
        $invoice = app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        usageMoveTo($invoice, SalesInvoiceStatus::PartiallyPaid, '400.0000', bcsub($invoice->total, '400.0000', 4));

        expect($this->probe->hasBeenApplied($taxCode))->toBeTrue();
    });

    it('is true for a paid invoice', function (): void {
        $taxCode = usageTaxCode();
        $invoice = app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        usageMoveTo($invoice, SalesInvoiceStatus::Paid, $invoice->total, '0.0000');

        // Settled and still frozen: the return that reported this tax has been filed.
        expect($this->probe->hasBeenApplied($taxCode))->toBeTrue();
    });

    it('is true for a cancelled invoice', function (): void {
        $taxCode = usageTaxCode();
        $invoice = app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        app(SalesInvoiceService::class)->cancel($invoice, 'Ordered in error', $this->owner);

        // Cancellation reverses the posting; it does not remove it. Both entries cite this rate, so the row
        // that explains them has to stay as it was.
        expect($this->probe->hasBeenApplied($taxCode))->toBeTrue();
    });
});

describe('two rows sharing one code', function (): void {
    it('freezes only the row an invoice actually cited', function (): void {
        // The effective-dated pair ADR 0006 exists for: 18% until June, 20% from July.
        $spring = usageTaxCode('VAT', '18', '2026-01-01', '2026-06-30');
        $summer = usageTaxCode('VAT', '20', '2026-07-01');

        expect($spring->getKey())->not->toBe($summer->getKey());

        // A June invoice resolves to the spring row by date.
        $invoice = app(SalesInvoiceService::class)->issue(usageDraft($spring, '2026-06-15'), $this->owner);

        expect(DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())
            ->value('tax_code_id'))->toBe((string) $spring->getKey());

        // Matching on the code string would freeze both. The identity is the row.
        expect($this->probe->hasBeenApplied($spring))->toBeTrue()
            ->and($this->probe->hasBeenApplied($summer))->toBeFalse();
    });

    it('leaves the unused row editable while the used one is frozen', function (): void {
        $spring = usageTaxCode('VAT', '18', '2026-01-01', '2026-06-30');
        $summer = usageTaxCode('VAT', '20', '2026-07-01');

        app(SalesInvoiceService::class)->issue(usageDraft($spring, '2026-06-15'), $this->owner);

        // The rule ADR 0006's effective-dating was designed to make possible: correct a future rate without
        // touching the history.
        $updated = app(TaxCodeService::class)->update($summer->fresh(), ['rate' => '25']);

        expect($updated->rate)->toBe('25.0000');

        $exception = catchPlatformException(
            fn () => app(TaxCodeService::class)->update($spring->fresh(), ['rate' => '25'])
        );

        expect($exception->problemCode())->toBe('tax-rate-already-applied');
    });
});

describe('tenant isolation', function (): void {
    it('does not let another tenant’s invoice freeze this row', function (): void {
        $mine = usageTaxCode();

        // Another workspace with its own `VAT` code and its own issued invoice citing it.
        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        app(ChartTemplateService::class)->apply($other['company']);
        app(FiscalCalendarService::class)->openYearContaining($other['company'], CarbonImmutable::parse('2026-06-15'));

        $otherRevenue = Account::query()->forCompany($other['company']->getKey())->where('code', '4100')->firstOrFail();
        $otherVat = Account::query()->forCompany($other['company']->getKey())->where('code', '2140')->firstOrFail();

        $otherCode = app(TaxCodeService::class)->create($other['company'], new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $otherVat->getKey(),
        ));

        $otherCustomer = app(CustomerService::class)->create(
            $other['company'],
            new CustomerData(name: 'Perera Ltd', code: 'PERERA'),
        );

        $otherDraft = app(SalesInvoiceService::class)->createDraft($other['company'], new SalesInvoiceData(
            customerId: (string) $otherCustomer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting services',
                quantity: '1',
                unitPrice: '1000.00',
                revenueAccountId: (string) $otherRevenue->getKey(),
                taxCode: 'VAT',
            )],
        ));

        app(SalesInvoiceService::class)->issue($otherDraft, $other['owner']);

        expect($this->probe->hasBeenApplied($otherCode))->toBeTrue();

        // Back in acme: same code string, different row, and nothing here has been invoiced.
        $this->withinTenant($this->acme['tenant']);

        expect($this->probe->hasBeenApplied($mine->fresh()))->toBeFalse();
    });
});

describe('the rules this activates', function (): void {
    it('refuses to change the rate of a used row', function (): void {
        $taxCode = usageTaxCode();
        app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        // Live for the first time. Change 18% to 20% on the row an invoice cited and that invoice's tax
        // silently becomes wrong, along with the return it was reported on.
        $exception = catchPlatformException(
            fn () => app(TaxCodeService::class)->update($taxCode->fresh(), ['rate' => '20'])
        );

        expect($exception->problemCode())->toBe('tax-rate-already-applied');
    });

    it('refuses to delete a used row', function (): void {
        $taxCode = usageTaxCode();
        app(SalesInvoiceService::class)->issue(usageDraft($taxCode), $this->owner);

        $exception = catchPlatformException(
            fn () => app(TaxCodeService::class)->delete($taxCode->fresh())
        );

        expect($exception->problemCode())->toBe('tax-code-in-use');
    });

    it('still allows an unused row to be changed and deleted', function (): void {
        $taxCode = usageTaxCode();

        // Nothing cites it, so the ordinary editing path is untouched — the rules narrowed, they did not
        // close.
        $updated = app(TaxCodeService::class)->update($taxCode->fresh(), ['rate' => '15']);

        expect($updated->rate)->toBe('15.0000');

        app(TaxCodeService::class)->delete($taxCode->fresh());

        expect(TaxCode::query()->whereKey($taxCode->getKey())->exists())->toBeFalse();
    });

    it('still allows a row cited only by a draft to be changed', function (): void {
        $taxCode = usageTaxCode();
        usageDraft($taxCode);

        // The draft distinction, proved through the service rather than only through the probe.
        $updated = app(TaxCodeService::class)->update($taxCode->fresh(), ['rate' => '15']);

        expect($updated->rate)->toBe('15.0000');
    });
});
