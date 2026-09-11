<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Carbon\CarbonImmutable;

/**
 * Foreign-currency bills — Stage 4 of Wave 9a (ADR 0022 §C). The base LKR ledger records a USD bill at its
 * booking rate, with the transaction face as the overlay on each line; the debits reconcile to the payable
 * face (`round(total × rate)`) so the entry balances in base with no FX line. A base-currency bill is
 * byte-for-byte today.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];   // base currency LKR, precision 2
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->tradePayables = Account::query()->forCompany($this->company->getKey())->where('code', '2110')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Overseas Co', code: 'OS'));
    $this->bills = app(BillService::class);
});

function fxBill(?string $currency = null, ?string $rate = null, string $unitPrice = '1000.00', ?string $taxCode = null): Bill
{
    static $counter = 0;
    $counter++;

    return test()->bills->post(
        test()->bills->createDraft(test()->company, new BillData(
            supplierId: (string) test()->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'FX-BILL-'.$counter,
            lines: [new BillLineData(
                description: 'Imported goods',
                quantity: '1',
                unitPrice: $unitPrice,
                expenseAccountId: (string) test()->purchases->getKey(),
                taxCode: $taxCode,
            )],
            currencyCode: $currency,
            exchangeRate: $rate,
        )),
        test()->owner,
    );
}

describe('foreign bill posting (ADR 0022 §C)', function (): void {
    it('records the bill in transaction currency and posts the ledger in base at the rate', function (): void {
        $bill = fxBill('USD', '300');

        // The bill's own money stays in USD.
        expect($bill->currency_code)->toBe('USD')
            ->and($bill->total)->toBe('1000.0000')
            ->and((float) $bill->exchange_rate)->toBe(300.0);

        $entry = JournalEntry::query()->with('lines')->findOrFail($bill->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        // Base LKR: Dr Expense 300,000 = Cr Trade Payables 300,000, each carrying the USD overlay.
        expect((float) $byAccount[$this->purchases->getKey()]->debit)->toBe(300000.0)
            ->and($byAccount[$this->purchases->getKey()]->transaction_currency_code)->toBe('USD')
            ->and((float) $byAccount[$this->purchases->getKey()]->transaction_amount)->toBe(1000.0)
            ->and((float) $byAccount[$this->tradePayables->getKey()]->credit)->toBe(300000.0)
            ->and($byAccount[$this->tradePayables->getKey()]->transaction_currency_code)->toBe('USD');
    });

    it('posts a base-currency bill byte-for-byte today, overlay NULL', function (): void {
        $bill = fxBill();   // no currency ⇒ base LKR

        expect($bill->currency_code)->toBe('LKR')
            ->and($bill->exchange_rate)->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($bill->journal_entry_id);

        foreach ($entry->lines as $line) {
            expect($line->transaction_currency_code)->toBeNull()
                ->and($line->transaction_amount)->toBeNull()
                ->and($line->exchange_rate)->toBeNull();
        }
    });

    it('reconciles the debits to the payable face and balances in base for a many-decimal rate with VAT', function (): void {
        $outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();
        $inputVat = Account::query()->forCompany($this->company->getKey())->where('code', '1170')->firstOrFail();
        app(TaxCodeService::class)->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'Value Added Tax',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $outputVat->getKey(),
            inputAccountId: (string) $inputVat->getKey(),
        ));

        $bill = fxBill('USD', '300.1234567', '1000.00', 'VAT');

        expect($bill->total)->toBe('1180.0000');   // USD 1000 + 18% VAT

        $entry = JournalEntry::query()->with('lines')->findOrFail($bill->journal_entry_id);

        // Exact decimal arithmetic (bccomp), never float equality.
        $debits = $entry->lines->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->debit, 4), '0');
        $credits = $entry->lines->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->credit, 4), '0');
        $payable = (string) $entry->lines->firstWhere('account_id', $this->tradePayables->getKey())->credit;

        // The entry balances in base, and the payable is the face round(1180 × 300.1234567) = 354145.68.
        expect(bccomp($debits, $credits, 2))->toBe(0)
            ->and(bccomp($payable, '354145.68', 2))->toBe(0)
            ->and(bccomp($debits, '354145.68', 2))->toBe(0);
    });

    it('refuses a foreign bill with a non-positive rate', function (): void {
        $exception = catchPlatformException(fn () => $this->bills->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'FX-BAD',
            lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '100.00', expenseAccountId: (string) $this->purchases->getKey())],
            currencyCode: 'USD',
            exchangeRate: '0',
        )));

        expect($exception->problemCode())->toBe('bill-rate-not-positive');
    });

    it('refuses a foreign bill with no rate', function (): void {
        $exception = catchPlatformException(fn () => $this->bills->createDraft($this->company, new BillData(
            supplierId: (string) $this->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'FX-NORATE',
            lines: [new BillLineData(description: 'x', quantity: '1', unitPrice: '100.00', expenseAccountId: (string) $this->purchases->getKey())],
            currencyCode: 'USD',
        )));

        expect($exception->problemCode())->toBe('bill-foreign-rate-required');
    });
});
