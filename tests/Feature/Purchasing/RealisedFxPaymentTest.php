<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\DTOs\PaymentAllocationData;
use Asids\Core\Purchasing\Application\DTOs\PaymentData;
use Asids\Core\Purchasing\Application\DTOs\SupplierData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\PaymentService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBePosted;
use Asids\Core\Purchasing\Domain\Exceptions\PaymentCannotBeRecorded;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Realised FX on a foreign supplier payment — Stages 5 & 6 of Wave 9a (ADR 0022 §D). A USD bill booked at one
 * rate and settled at another realises the difference to `Foreign Exchange Gain/(Loss)`; the base entry
 * balances with the FX line as the plug. A base-currency payment is byte-for-byte Wave 8a/8b.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];   // base LKR, precision 2
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->purchases = Account::query()->forCompany($this->company->getKey())->where('code', '5100')->firstOrFail();
    $this->tradePayables = Account::query()->forCompany($this->company->getKey())->where('code', '2110')->firstOrFail();
    $this->bank = Account::query()->forCompany($this->company->getKey())->where('code', '1120')->firstOrFail();
    $this->fx = Account::query()->forCompany($this->company->getKey())->where('code', '4910')->firstOrFail();
    $this->whtPayable = Account::query()->forCompany($this->company->getKey())->where('code', '2180')->firstOrFail();

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(name: 'Overseas Co', code: 'OS'));
    $this->bills = app(BillService::class);
    $this->payments = app(PaymentService::class);
});

/**
 * A posted USD bill (no VAT) at the given booking rate.
 */
function usdBill(string $rate, string $unitPrice = '1000.00'): Bill
{
    static $counter = 0;
    $counter++;

    return test()->bills->post(
        test()->bills->createDraft(test()->company, new BillData(
            supplierId: (string) test()->supplier->getKey(),
            billDate: CarbonImmutable::parse('2026-06-15'),
            supplierInvoiceNumber: 'FXP-'.$counter,
            lines: [new BillLineData(description: 'Import', quantity: '1', unitPrice: $unitPrice, expenseAccountId: (string) test()->purchases->getKey())],
            currencyCode: 'USD',
            exchangeRate: $rate,
        )),
        test()->owner,
    );
}

/**
 * @return array<string, JournalLine> lines keyed by account_id
 */
function fxEntryLines(SupplierPayment $payment)
{
    return JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id)->lines->keyBy('account_id');
}

describe('realised FX on settlement (ADR 0022 §D)', function (): void {
    it('posts Dr Trade Payables + Dr FX Loss = Cr Bank when the rate moved against us (@ 310)', function (): void {
        $bill = usdBill('300');   // payable base 300,000

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',
        ), $this->owner);

        expect($payment->currency_code)->toBe('USD')
            ->and((float) $payment->exchange_rate)->toBe(310.0)
            ->and($payment->amount)->toBe('1000.0000');

        $lines = fxEntryLines($payment);

        expect((float) $lines[$this->tradePayables->getKey()]->debit)->toBe(300000.0)
            ->and((float) $lines[$this->fx->getKey()]->debit)->toBe(10000.0)
            ->and((float) $lines[$this->bank->getKey()]->credit)->toBe(310000.0)
            // Bill fully settled in USD.
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('records the per-line overlay: payables at the bill rate, cash at the payment rate, FX plug with none', function (): void {
        $bill = usdBill('300');   // payable base 300,000, overlay USD 1000 @ 300

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',
        ), $this->owner);

        $lines = fxEntryLines($payment);

        // Trade Payables relieved at the BILL's booking rate (300), carrying the USD face — the fork-6 per-bill
        // audit trail: the payable comes off at the rate it went on.
        $tp = $lines[$this->tradePayables->getKey()];
        expect($tp->transaction_currency_code)->toBe('USD')
            ->and((float) $tp->transaction_amount)->toBe(1000.0)
            ->and((float) $tp->exchange_rate)->toBe(300.0);

        // Bank at the PAYMENT rate (310), carrying the same USD face.
        $bank = $lines[$this->bank->getKey()];
        expect($bank->transaction_currency_code)->toBe('USD')
            ->and((float) $bank->transaction_amount)->toBe(1000.0)
            ->and((float) $bank->exchange_rate)->toBe(310.0);

        // The FX plug is a base-only line — no transaction overlay.
        $fx = $lines[$this->fx->getKey()];
        expect($fx->transaction_currency_code)->toBeNull()
            ->and($fx->transaction_amount)->toBeNull()
            ->and($fx->exchange_rate)->toBeNull();
    });

    it('posts Dr Trade Payables = Cr Bank + Cr FX Gain when the rate moved in our favour (@ 290)', function (): void {
        $bill = usdBill('300');

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '290',
        ), $this->owner);

        $lines = fxEntryLines($payment);

        expect((float) $lines[$this->tradePayables->getKey()]->debit)->toBe(300000.0)
            ->and((float) $lines[$this->bank->getKey()]->credit)->toBe(290000.0)
            ->and((float) $lines[$this->fx->getKey()]->credit)->toBe(10000.0);
    });

    it('posts no FX line when the payment rate matches the booking rate', function (): void {
        $bill = usdBill('300');

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '300',
        ), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);

        // Dr Trade Payables 300,000 = Cr Bank 300,000 — no FX line resolved.
        expect($entry->lines)->toHaveCount(2)
            ->and($entry->lines->pluck('account_id')->all())->not->toContain((string) $this->fx->getKey());
    });

    it('realises FX only on the portion settled, on a partial payment', function (): void {
        $bill = usdBill('300');   // USD 1,000 @ 300

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '400.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '400.00')],
            currencyCode: 'USD',
            exchangeRate: '310',
        ), $this->owner);

        $lines = fxEntryLines($payment);

        // Relief 400×300 = 120,000; cash 400×310 = 124,000; FX loss = 4,000 (only on the 400 settled).
        expect((float) $lines[$this->tradePayables->getKey()]->debit)->toBe(120000.0)
            ->and((float) $lines[$this->fx->getKey()]->debit)->toBe(4000.0)
            ->and((float) $lines[$this->bank->getKey()]->credit)->toBe(124000.0)
            ->and($bill->fresh()->status->value)->toBe('partially_paid')
            ->and($bill->fresh()->amount_due)->toBe('600.0000');
    });

    it('zeroes the payable base exactly across several partial settlements at a drifted rate (fork 2)', function (): void {
        // USD 100 @ 300.005 → payable face round(100 × 300.005) = round(30000.50) = 30000.50 (LKR, 2dp).
        $bill = usdBill('300.005', '100.00');
        $payableBase = JournalEntry::query()->with('lines')->findOrFail($bill->journal_entry_id)
            ->lines->firstWhere('account_id', $this->tradePayables->getKey())->credit;

        // Three partials at the booking rate (so there is no FX — the relief telescoping is isolated).
        $reliefTotal = '0';
        foreach (['33.33', '33.33', '33.34'] as $amount) {
            $payment = $this->payments->record($this->company, new PaymentData(
                supplierId: (string) $this->supplier->getKey(),
                bankAccountId: (string) $this->bank->getKey(),
                paymentDate: CarbonImmutable::parse('2026-06-15'),
                amount: $amount,
                allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: $amount)],
                currencyCode: 'USD',
                exchangeRate: '300.005',
            ), $this->owner);

            $reliefTotal = bcadd($reliefTotal, (string) fxEntryLines($payment)[$this->tradePayables->getKey()]->debit, 4);
        }

        // The Trade Payables relief across the three payments sums to exactly the original payable face — the
        // incremental relief telescopes, so the bill's payable zeroes with no sub-cent residue (fork 2).
        expect(bccomp($reliefTotal, (string) $payableBase, 2))->toBe(0)
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('balances the base entry to the cent for a many-decimal rate', function (): void {
        $bill = usdBill('300');

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '312.7654321',
        ), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $debits = $entry->lines->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->debit, 4), '0');
        $credits = $entry->lines->reduce(fn (string $c, $l): string => bcadd($c, (string) $l->credit, 4), '0');

        expect(bccomp($debits, $credits, 2))->toBe(0);
    });

    it('refuses a payment whose currency differs from the bill it settles (same-currency only)', function (): void {
        $bill = usdBill('300');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            // No currencyCode ⇒ base LKR payment against a USD bill.
        ), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-currency-mismatch');
    });

    it('refuses a foreign payment with a non-positive rate', function (): void {
        $bill = usdBill('300');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '0',
        ), $this->owner));

        expect($exception->problemCode())->toBe('payment-rate-not-positive');
    });

    it('settles a USD bill with a lower-case "usd" payment — the code is normalised before the same-currency guard', function (): void {
        $bill = usdBill('300');   // payable base 300,000

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'usd',   // lower case: must normalise to USD and match the USD bill
            exchangeRate: '300',
        ), $this->owner);

        // Stored uppercase, settlement succeeded, no phantom mismatch.
        expect($payment->currency_code)->toBe('USD')
            ->and($bill->fresh()->status->value)->toBe('paid');
    });

    it('refuses a malformed payment currency code', function (): void {
        $bill = usdBill('300');

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'US',
            exchangeRate: '300',
        ), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('payment-currency-code-malformed');
    });

    it('refuses a foreign settlement when the FX account is missing', function (): void {
        $bill = usdBill('300');

        DB::table('accounts')->where('id', $this->fx->getKey())->update(['system_key' => null, 'is_system' => false]);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',   // non-matching rate ⇒ an FX line is needed
        ), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBePosted::class)
            ->and($exception->problemCode())->toBe('fx-account-missing');
    });

    it('refuses a foreign settlement when the FX account has been reclassified to a non-income type', function (): void {
        $bill = usdBill('300');

        // 4910 reclassified after provisioning — posting realised FX to a non-income account misstates the period.
        DB::table('accounts')->where('id', $this->fx->getKey())->update(['type' => 'asset', 'normal_balance' => 'debit']);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',   // non-matching rate ⇒ an FX line is needed
        ), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBePosted::class)
            ->and($exception->problemCode())->toBe('fx-account-wrong-type');
    });

    it('refuses a foreign settlement when the FX account no longer accepts postings', function (): void {
        $bill = usdBill('300');

        // 4910 archived after provisioning — a foreign settlement that needs the FX plug cannot use it.
        DB::table('accounts')->where('id', $this->fx->getKey())->update(['is_active' => false, 'archived_at' => now()]);

        $exception = catchPlatformException(fn () => $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',   // non-matching rate ⇒ an FX line is needed
        ), $this->owner));

        expect($exception)->toBeInstanceOf(PaymentCannotBePosted::class)
            ->and($exception->problemCode())->toBe('posting-account-not-postable');
    });

    it('a base-currency payment is byte-for-byte Wave 8a and never resolves the FX account', function (): void {
        // Break the FX account: a base payment must not touch it.
        DB::table('accounts')->where('id', $this->fx->getKey())->update(['system_key' => null, 'is_system' => false]);

        $baseBill = $this->bills->post(
            $this->bills->createDraft($this->company, new BillData(
                supplierId: (string) $this->supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'BASE-1',
                lines: [new BillLineData(description: 'Local', quantity: '1', unitPrice: '1000.00', expenseAccountId: (string) $this->purchases->getKey())],
            )),
            $this->owner,
        );

        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $this->supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $baseBill->getKey(), amount: '1000.00')],
        ), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);

        expect($payment->currency_code)->toBe('LKR')
            ->and($payment->exchange_rate)->toBeNull()
            ->and($entry->lines)->toHaveCount(2)   // grouped Dr Trade Payables = Cr Bank, no per-bill split, no FX
            ->and($entry->lines->pluck('transaction_currency_code')->unique()->all())->toBe([null]);
    });
});

describe('WHT × FX composed (ADR 0022 §D4)', function (): void {
    it('withholds in the payment currency and realises FX on the gross (worked example)', function (): void {
        // Withholding supplier at 5%.
        $supplier = app(SupplierService::class)->create($this->company, new SupplierData(
            name: 'WHT Overseas', code: 'WHTOS', whtApplicable: true, whtRate: '5.00',
        ));

        $bill = test()->bills->post(
            test()->bills->createDraft($this->company, new BillData(
                supplierId: (string) $supplier->getKey(),
                billDate: CarbonImmutable::parse('2026-06-15'),
                supplierInvoiceNumber: 'FX-WHT-1',
                lines: [new BillLineData(description: 'Service', quantity: '1', unitPrice: '1000.00', expenseAccountId: (string) $this->purchases->getKey())],
                currencyCode: 'USD',
                exchangeRate: '300',
            )),
            $this->owner,
        );

        // Pay USD 1,000 @ 310, withholding 5% (USD 50). Net cash USD 950.
        $payment = $this->payments->record($this->company, new PaymentData(
            supplierId: (string) $supplier->getKey(),
            bankAccountId: (string) $this->bank->getKey(),
            paymentDate: CarbonImmutable::parse('2026-06-15'),
            amount: '1000.00',
            allocations: [new PaymentAllocationData(billId: (string) $bill->getKey(), amount: '1000.00')],
            currencyCode: 'USD',
            exchangeRate: '310',
        ), $this->owner);

        // wht_amount snapshotted in the transaction currency (USD 50).
        expect($payment->wht_amount)->toBe('50.0000');

        $lines = fxEntryLines($payment);

        // Dr TP 300,000 + Dr FX Loss 10,000 = Cr Bank 294,500 (950×310) + Cr WHT 15,500 (50×310).
        expect((float) $lines[$this->tradePayables->getKey()]->debit)->toBe(300000.0)
            ->and((float) $lines[$this->fx->getKey()]->debit)->toBe(10000.0)
            ->and((float) $lines[$this->bank->getKey()]->credit)->toBe(294500.0)
            ->and((float) $lines[$this->whtPayable->getKey()]->credit)->toBe(15500.0);
    });
});
