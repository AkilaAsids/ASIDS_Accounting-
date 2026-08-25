<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptPostingMap;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\ReceiptAllocation;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Finer-grained unit coverage for the receipt lane, alongside QA's `RecordReceiptTest` /
 * `ReceiptAuthorizationTest`. The pure `ReceiptPostingMap` earns exhaustive exercise here — its bank/receivable
 * refusal paths raise `ReceiptCannotBePosted` and are reached by feeding it receipt-shaped input directly, which
 * is legitimate precisely because the map writes nothing. The DTOs, enum, model helpers and exception factories
 * are covered here too, so the Money edges and the refusal messages are proven rather than assumed.
 */
beforeEach(function (): void {
    $this->ws = $this->createWorkspace('mapco');
    $this->withinTenant($this->ws['tenant']);
    $this->company = $this->ws['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));
});

function rcptMapAccount(string $code): Account
{
    return Account::query()->forCompany((string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * A saved, hand-built posted receipt with the given [invoiceId => amount] allocations, bypassing the service so
 * the pure map can be exercised on inputs the validated path would never produce.
 *
 * @param  array<string, string>  $allocations
 */
function rcptMapFixture(array $allocations, string $amount, ?string $bankCode = null): CustomerReceipt
{
    $receipt = new CustomerReceipt;
    $receipt->company_id = test()->company->getKey();
    $receipt->customer_id = test()->mapCustomer->getKey();
    $receipt->number = 'RCT-TEST-0001';
    $receipt->receipt_date = CarbonImmutable::parse('2026-06-20');
    $receipt->currency_code = test()->company->base_currency_code;
    $receipt->amount = $amount;
    $receipt->payment_method = PaymentMethod::Cash->value;
    $receipt->bank_account_id = rcptMapAccount($bankCode ?? '1120')->getKey();
    $receipt->status = 'posted';
    $receipt->save();

    foreach ($allocations as $invoiceId => $lineAmount) {
        $allocation = new ReceiptAllocation;
        $allocation->company_id = test()->company->getKey();
        $allocation->customer_receipt_id = $receipt->getKey();
        $allocation->sales_invoice_id = $invoiceId;
        $allocation->amount = $lineAmount;
        $allocation->save();
    }

    return $receipt->fresh();
}

describe('ReceiptPostingMap — the balanced two-line entry', function (): void {
    it('debits the bank and credits trade receivables for the receipt amount', function (): void {
        $this->mapCustomer = rcptMapCustomer();
        $invoice = rcptMapInvoice('1000.00');

        $receipt = rcptMapFixture([(string) $invoice->getKey() => '1000.0000'], '1000.0000');

        $lines = app(ReceiptPostingMap::class)->for($receipt);

        expect($lines)->toHaveCount(2)
            ->and((string) $lines[0]->accountId)->toBe((string) rcptMapAccount('1120')->getKey())
            ->and($lines[0]->debit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[0]->credit)->toBeNull()
            ->and((string) $lines[1]->accountId)->toBe((string) rcptMapAccount('1130')->getKey())
            ->and($lines[1]->credit?->toDecimalString())->toBe('1000.0000')
            ->and($lines[1]->debit)->toBeNull();
    });
});

describe('ReceiptPostingMap — refusals raised by the pure map', function (): void {
    beforeEach(function (): void {
        $this->mapCustomer = rcptMapCustomer();
    });

    it('refuses a receipt with no allocations', function (): void {
        $receipt = rcptMapFixture([], '1000.0000');

        expect(fn () => app(ReceiptPostingMap::class)->for($receipt))
            ->toThrow(ReceiptCannotBePosted::class);
    });

    it('refuses a bank account that is not an asset', function (): void {
        $invoice = rcptMapInvoice('1000.00');
        $receipt = rcptMapFixture([(string) $invoice->getKey() => '1000.0000'], '1000.0000', bankCode: '4100');

        expect(fn () => app(ReceiptPostingMap::class)->for($receipt))
            ->toThrow(ReceiptCannotBePosted::class);
    });

    it('refuses a bank account that no longer accepts postings', function (): void {
        $invoice = rcptMapInvoice('1000.00');
        $receipt = rcptMapFixture([(string) $invoice->getKey() => '1000.0000'], '1000.0000');

        DB::table('accounts')->where('id', rcptMapAccount('1120')->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        expect(fn () => app(ReceiptPostingMap::class)->for($receipt->fresh()))
            ->toThrow(ReceiptCannotBePosted::class);
    });
});

describe('the DTOs build from a payload', function (): void {
    it('builds ReceiptData with allocations and an enum method', function (): void {
        $data = ReceiptData::fromArray([
            'customer_id' => 'cust-1',
            'receipt_date' => '2026-06-20',
            'amount' => ' 1000.00 ',
            'payment_method' => 'cheque',
            'bank_account_id' => 'acc-1',
            'reference' => '  CHQ-1  ',
            'branch_id' => '',
            'allocations' => [
                ['sales_invoice_id' => 'inv-1', 'amount' => '600.00'],
                ['sales_invoice_id' => 'inv-2', 'amount' => '400.00'],
            ],
        ]);

        expect($data->amount)->toBe('1000.00')
            ->and($data->paymentMethod)->toBe(PaymentMethod::Cheque)
            ->and($data->reference)->toBe('CHQ-1')
            ->and($data->branchId)->toBeNull()
            ->and($data->allocations)->toHaveCount(2)
            ->and($data->allocations[0])->toBeInstanceOf(ReceiptAllocationData::class)
            ->and($data->allocations[1]->amount)->toBe('400.00');
    });

    it('accepts a PaymentMethod instance already resolved', function (): void {
        $data = ReceiptData::fromArray([
            'customer_id' => 'c',
            'receipt_date' => '2026-06-20',
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Card,
            'bank_account_id' => 'a',
            'allocations' => [],
        ]);

        expect($data->paymentMethod)->toBe(PaymentMethod::Card)
            ->and($data->allocations)->toBe([]);
    });
});

describe('PaymentMethod', function (): void {
    it('labels every case', function (): void {
        expect(PaymentMethod::Cash->label())->toBe('Cash')
            ->and(PaymentMethod::BankTransfer->label())->toBe('Bank transfer')
            ->and(PaymentMethod::Cheque->label())->toBe('Cheque')
            ->and(PaymentMethod::Card->label())->toBe('Card');
    });
});

describe('model money helpers', function (): void {
    it('reads receipt and allocation amounts as Money', function (): void {
        $this->mapCustomer = rcptMapCustomer();
        $invoice = rcptMapInvoice('1000.00');
        $receipt = rcptMapFixture([(string) $invoice->getKey() => '1000.0000'], '1000.0000');

        expect($receipt->amountMoney()->toDecimalString())->toBe('1000.0000')
            ->and($receipt->allocations->first()->amountMoney($receipt->currency_code)->toDecimalString())
            ->toBe('1000.0000');
    });
});

describe('a posted receipt is immutable at the database', function (): void {
    it('refuses UPDATE and DELETE on the receipt and its allocations', function (): void {
        $this->mapCustomer = rcptMapCustomer();
        $invoice = rcptMapInvoice('1000.00');
        $receipt = rcptMapFixture([(string) $invoice->getKey() => '1000.0000'], '1000.0000');

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())
            ->update(['amount' => '5.0000']))->toThrow(QueryException::class);

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())->delete())
            ->toThrow(QueryException::class);

        expect(fn () => DB::table('receipt_allocations')->where('customer_receipt_id', $receipt->getKey())
            ->update(['amount' => '5.0000']))->toThrow(QueryException::class);
    });
});

describe('exception factories carry actionable, coded messages', function (): void {
    it('names each receipt/allocation/posting refusal', function (): void {
        expect(ReceiptCannotBeRecorded::currencyNotBase('USD', 'LKR')->problemCode())
            ->toBe('receipt-currency-not-base')
            ->and(ReceiptCannotBeRecorded::withoutAllocations()->problemCode())->toBe('receipt-has-no-allocations')
            ->and(ReceiptCannotBeAllocated::unknownInvoice('X')->problemCode())
            ->toBe('receipt-allocation-unknown-invoice')
            ->and(ReceiptCannotBeAllocated::exceedsAmountDue('INV-1', '400.0000', '300.0000')->problemCode())
            ->toBe('receipt-allocation-exceeds-amount-due')
            ->and(ReceiptCannotBePosted::receivableAccountsDiffer(2)->getMessage())->toContain('2 different');
    });
});

/**
 * A customer for the map suite, created through the real service.
 */
function rcptMapCustomer(): Customer
{
    return app(CustomerService::class)->create(
        test()->company,
        new CustomerData(name: 'Map Traders', code: 'MAP'),
    );
}

/**
 * An issued invoice for the map suite's customer.
 */
function rcptMapInvoice(string $unitPrice): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(
        test()->company,
        new SalesInvoiceData(
            customerId: (string) test()->mapCustomer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting',
                quantity: '1',
                unitPrice: $unitPrice,
                revenueAccountId: (string) rcptMapAccount('4100')->getKey(),
            )],
        ),
    );

    return app(SalesInvoiceService::class)->issue($draft, test()->ws['owner']);
}
