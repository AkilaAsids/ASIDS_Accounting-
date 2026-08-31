<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * QA gap-closing test (Stage 5 verification, not part of the RED wave suite).
 *
 * THE UNTESTED BOUNDARY
 * ---------------------
 * ADR 0017 §D and Gate-1 #2 fix the rule as `wht <= allocation` — NON-STRICT. It is enforced twice, both at the
 * boundary of `<=`: the service refusal `withholdingExceedsAllocation()` fires on `wht->isGreaterThan(alloc)`
 * (strict `>`, so equality is accepted), and the same-row database CHECK is `wht_amount <= amount` (so equality
 * is accepted). The shipped wave suite exercises `wht < alloc` (accepted) and `wht > alloc` (refused, 1100 on a
 * 1000 line) but never the transition point `wht == alloc`. A regression that tightened either guard to a strict
 * `<` — refusing a fully-withheld line — would violate the ADR yet pass the whole existing suite unnoticed.
 *
 * This pins that boundary: a line whose withholding exactly equals its own allocation is ACCEPTED, stored at the
 * CHECK boundary, and posted as a balanced `Dr Bank / Dr WHT / Cr AR` entry. Composed with a second, un-withheld
 * line so the receipt needs no artificial remainder — the withheld line clears to zero net cash while the other
 * carries the cash, exactly the arithmetic the settlement invariant permits (`settlement = amount + Σ wht`).
 *
 * Named helpers are file-unique (`whtEdge*`) so they do not collide with the wave suite's global `whtSuite*`.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = whtEdgeAccount('4100');
    $this->receivables = whtEdgeAccount('1130');
    $this->bank = whtEdgeAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function whtEdgeAccount(string $code): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

function whtEdgeReceivableAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::WHT_RECEIVABLE)
        ->firstOrFail();
}

function whtEdgeInvoice(string $unitPrice): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse('2026-06-15'),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * @param  list<ReceiptAllocationData>  $allocations
 */
function whtEdgeReceipt(array $allocations, string $amount): CustomerReceipt
{
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse('2026-06-20'),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF-EDGE',
        allocations: $allocations,
    ), test()->owner);
}

function whtEdgeHeldCreditFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

describe('the wht <= allocation boundary (ADR 0017 §D, Gate-1 #2)', function (): void {
    it('accepts a line whose withholding exactly equals its allocation and posts it balanced', function (): void {
        // Invoice A is fully withheld — wht 500 == alloc 500, the exact CHECK/service boundary — so net cash on
        // it is zero. Invoice B carries the cash (alloc 500, no WHT). settlement = 500 cash + 500 wht = 1000 =
        // Σ alloc, so there is no remainder. If either guard were a strict `<`, recording would be refused here.
        $a = whtEdgeInvoice('500.00');
        $b = whtEdgeInvoice('500.00');

        $receipt = whtEdgeReceipt([
            new ReceiptAllocationData(salesInvoiceId: (string) $a->getKey(), amount: '500.00', whtAmount: '500.00'),
            new ReceiptAllocationData(salesInvoiceId: (string) $b->getKey(), amount: '500.00', whtAmount: '0.00'),
        ], '500.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(3)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1000.0)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(500.0)
            ->and((float) $byAccount[whtEdgeReceivableAccount()->getKey()]->debit)->toBe(500.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0);

        // No remainder: the withheld line contributes zero net cash, the other exactly matches the cash received.
        expect(whtEdgeHeldCreditFor((string) $receipt->getKey()))->toBeNull();

        // The boundary value is actually persisted on the fully-withheld allocation row — wht_amount == amount.
        $rowA = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())
            ->where('sales_invoice_id', $a->getKey())
            ->first();

        expect($rowA->wht_amount)->toBe('500.0000')
            ->and($rowA->amount)->toBe('500.0000');

        // Each invoice's gross receivable is cleared in full, independent of how much of it was withheld.
        expect($a->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($a->amount_due)->toBe('0.0000')
            ->and($b->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($b->amount_due)->toBe('0.0000');
    });
});
