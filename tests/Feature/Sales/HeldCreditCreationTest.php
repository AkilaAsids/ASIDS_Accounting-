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
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\ReceiptHeldCredit;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a receipt that leaves a remainder held as Customer Advances — ADR 0016 §C (record + posting) and
 * §B (the held-credit table's own CHECKs, RLS and conditional immutability). Stages 3 and 2.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test references only the INTENDED API ADR 0016 pins down:
 *
 *   - `ReceiptService::record()` accepts `Σ allocations < amount` (only over-allocation still refuses), holding
 *     the remainder as one `receipt_held_credits` row and posting THREE lines:
 *         Dr Bank amount / Cr Trade Receivables Σalloc / Cr Customer Advances remainder.
 *   - A fully-allocated receipt is byte-for-byte unchanged: two lines, no Customer Advances line, no held
 *     credit (AC-CR-6.1 regression safety).
 *   - `receipt_held_credits` (per-receipt): original_amount / applied_amount / remaining_amount / status, one
 *     row per receipt (UNIQUE customer_receipt_id), with the balance-tie, non-negative, applied≤original,
 *     cancelled⇒remaining=0, original>0 and status-IN CHECKs, FORCED RLS, and a conditional immutability
 *     trigger (frozen apart from applied/remaining/status).
 *   - `ReceiptHeldCredit` model with `receipt()`/`customer()` relations and decimal:4 money casts.
 *
 * THE ONE DELIBERATE FLIP (AC-CR-4.1, made visible)
 * -------------------------------------------------
 * ADR 0014 refused BOTH over- and under-allocation. This wave flips only the under half to acceptance; the
 * over half stays refused verbatim. Both halves live in this file so the split reads as a reviewed decision.
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup runs through the shipped `record()`/`issue()`. A remainder receipt is refused today with
 * `receipt-not-fully-allocated`, so the acceptance tests fail on that refusal; the posting/held-credit tests
 * fail because `Account::CUSTOMER_ADVANCES` is undefined and the `receipt_held_credits` table does not exist;
 * the model tests fail because `ReceiptHeldCredit` does not exist. Every failure names an absent decision.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);

    $this->revenue = holdSuiteAccount('4100');
    $this->receivables = holdSuiteAccount('1130');
    $this->bank = holdSuiteAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function holdSuiteAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * The Customer Advances account, resolved by key exactly as the posting map must. Errors RED until the key
 * and the account exist.
 */
function holdSuiteAdvancesAccount(): Account
{
    return Account::query()
        ->forCompany((string) test()->company->getKey())
        ->withSystemKey(Account::CUSTOMER_ADVANCES)
        ->firstOrFail();
}

function holdSuiteInvoice(string $unitPrice = '1000.00', string $date = '2026-06-15', ?string $customerId = null): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($date),
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
 * Records a receipt for the suite's customer. `amount` may exceed Σ allocations — the remainder is what this
 * wave newly holds.
 *
 * @param  array<string, string>  $allocations  [invoiceId => amount]
 */
function holdSuiteReceipt(
    array $allocations,
    string $amount,
    string $date = '2026-06-20',
    ?string $customerId = null,
): CustomerReceipt {
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: (string) test()->bank->getKey(),
        reference: 'REF-1',
        allocations: array_map(
            static fn (string $invoiceId, string $lineAmount): ReceiptAllocationData => new ReceiptAllocationData(
                salesInvoiceId: $invoiceId,
                amount: $lineAmount,
            ),
            array_keys($allocations),
            array_values($allocations),
        ),
    ), test()->owner);
}

/**
 * The single held-credit row for a receipt, as a raw record (robust while the model may not yet exist).
 */
function heldCreditFor(string $receiptId): ?object
{
    return DB::table('receipt_held_credits')->where('customer_receipt_id', $receiptId)->first();
}

describe('a fully-allocated receipt is unchanged (AC-CR-6.1 regression safety)', function (): void {
    it('holds no credit and posts the identical two-line entry', function (): void {
        $invoice = holdSuiteInvoice('1000.00');

        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // No held credit created for a zero remainder.
        expect(heldCreditFor((string) $receipt->getKey()))->toBeNull();

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        expect($entry->lines)->toHaveCount(2)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0)
            // The Customer Advances account is not touched at all when there is no remainder.
            ->and($byAccount->has(holdSuiteAdvancesAccount()->getKey()))->toBeFalse();

        expect($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Paid);
    });
});

describe('a receipt with a remainder is accepted and held', function (): void {
    it('accepts a partial allocation and records the remainder as customer advances', function (): void {
        // AC-CR-1.1 — 1,000 received, 700 allocated, 300 held. The named allocation posts as it does today.
        $invoice = holdSuiteInvoice('1000.00');

        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');

        // The allocated invoice moved by exactly the allocation, not the whole receipt.
        expect($invoice->refresh()->amount_paid)->toBe('700.0000')
            ->and($invoice->amount_due)->toBe('300.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::PartiallyPaid);

        $held = heldCreditFor((string) $receipt->getKey());

        expect($held)->not->toBeNull()
            ->and($held->original_amount)->toBe('300.0000')
            ->and($held->remaining_amount)->toBe('300.0000')
            ->and($held->applied_amount)->toBe('0.0000')
            ->and($held->status)->toBe('active')
            ->and($held->customer_id)->toBe((string) $this->customer->getKey())
            ->and($held->company_id)->toBe((string) $this->company->getKey())
            ->and($held->tenant_id)->toBe((string) $this->acme['tenant']->getKey())
            ->and($held->currency_code)->toBe($receipt->currency_code);
    });

    it('posts three balanced lines: Dr Bank / Cr AR Σalloc / Cr Customer Advances remainder', function (): void {
        // AC-CR-1.2 / AC-CR-2.1 — the variable-line posting, balancing by construction.
        $invoice = holdSuiteInvoice('1000.00');

        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(3)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1000.0)
            ->and((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(700.0)
            ->and((float) $byAccount[holdSuiteAdvancesAccount()->getKey()]->credit)->toBe(300.0);
    });

    it('moves each invoice independently and holds one remainder across a multi-invoice receipt', function (): void {
        // AC-CR-2.1 across invoices — AR credited Σalloc as one line, one held-credit row for the remainder.
        $small = holdSuiteInvoice('300.00');
        $large = holdSuiteInvoice('900.00');

        $receipt = holdSuiteReceipt([
            (string) $small->getKey() => '300.00',
            (string) $large->getKey() => '700.00',
        ], '1200.00'); // Σalloc 1000, remainder 200.

        expect($small->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($large->refresh()->amount_paid)->toBe('700.0000')
            ->and($large->status)->toBe(SalesInvoiceStatus::PartiallyPaid);

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $byAccount = $entry->lines->keyBy('account_id');

        expect((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1200.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0)
            ->and((float) $byAccount[holdSuiteAdvancesAccount()->getKey()]->credit)->toBe(200.0);

        $held = heldCreditFor((string) $receipt->getKey());

        expect($held->original_amount)->toBe('200.0000')
            ->and($held->remaining_amount)->toBe('200.0000');
    });

    it('refuses an amount finer than the currency precision', function (): void {
        // ADR 0016 Gate-2 amendment: `1000.3333` is not an amount anyone can pay in a two-decimal currency,
        // and accepting it would create a remainder the ledger (which posts at currency_precision) could never
        // match. Refused at record(), so no phantom remainder is ever held.
        $invoice = holdSuiteInvoice('900.00');

        $exception = catchPlatformException(
            fn () => holdSuiteReceipt([(string) $invoice->getKey() => '700.1111'], '1000.3333')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('receipt-amount-exceeds-currency-precision');
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('holds the remainder at the currency precision, consistent with the ledger', function (): void {
        // ADR 0016 Gate-2 amendment: held credit lives at the company's currency_precision, so the subledger
        // record and the Customer Advances posting line agree exactly and the entry balances. (Replaces the
        // former 4dp case, whose 1000.3333/700.1111 input is now refused above.)
        $invoice = holdSuiteInvoice('900.00');

        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.10'], '1000.25'); // remainder 300.15.

        $held = heldCreditFor((string) $receipt->getKey());

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);
        $advancesCredit = $entry->lines->keyBy('account_id')[holdSuiteAdvancesAccount()->getKey()]->credit;

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($held->original_amount)->toBe('300.1500')
            ->and($held->remaining_amount)->toBe('300.1500')
            // The posting line equals the held-credit record exactly — subledger and ledger agree.
            ->and((float) $advancesCredit)->toBe(300.15)
            ->and((float) $advancesCredit)->toBe((float) $held->original_amount)
            // And the entry balances at the currency precision.
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1000.25);
    });

    it('tracks credit per receipt: two remainder receipts produce two distinct rows', function (): void {
        // AC-CR-3.1 / Gate-1 #4 — per-receipt, not pooled. Each receipt owns its own credit record.
        $invoiceA = holdSuiteInvoice('1000.00');
        $invoiceB = holdSuiteInvoice('1000.00');

        $r1 = holdSuiteReceipt([(string) $invoiceA->getKey() => '300.00'], '500.00', date: '2026-06-20');
        $r2 = holdSuiteReceipt([(string) $invoiceB->getKey() => '300.00'], '500.00', date: '2026-06-21');

        $h1 = heldCreditFor((string) $r1->getKey());
        $h2 = heldCreditFor((string) $r2->getKey());

        expect($h1)->not->toBeNull()
            ->and($h2)->not->toBeNull()
            ->and($h1->id)->not->toBe($h2->id)
            ->and($h1->original_amount)->toBe('200.0000')
            ->and($h2->original_amount)->toBe('200.0000')
            ->and(DB::table('receipt_held_credits')
                ->where('company_id', $this->company->getKey())->count())->toBe(2);
    });
});

describe('the ReceiptHeldCredit model', function (): void {
    it('relates back to its receipt and customer, casting money to four decimals', function (): void {
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');

        $held = ReceiptHeldCredit::query()->where('customer_receipt_id', $receipt->getKey())->firstOrFail();

        expect((string) $held->receipt->getKey())->toBe((string) $receipt->getKey())
            ->and((string) $held->customer->getKey())->toBe((string) $this->customer->getKey())
            ->and($held->original_amount)->toBe('300.0000')
            ->and($held->remaining_amount)->toBe('300.0000')
            ->and($held->applied_amount)->toBe('0.0000');
    });
});

describe('the deliberate flip: under accepted, over still refused (AC-CR-4.1)', function (): void {
    it('still refuses a receipt over-allocated against its own amount', function (): void {
        // KEPT EXACTLY from ADR 0014 — over-allocation is untouched by this wave.
        $invoice = holdSuiteInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => holdSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '600.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('still refuses when allocations across invoices sum to more than the receipt', function (): void {
        // KEPT EXACTLY — the multi-invoice over-sum refusal.
        $first = holdSuiteInvoice('1000.00');
        $second = holdSuiteInvoice('1000.00');

        $exception = catchPlatformException(fn () => holdSuiteReceipt([
            (string) $first->getKey() => '600.00',
            (string) $second->getKey() => '600.00',
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
        expect($first->refresh()->amount_paid)->toBe('0.0000')
            ->and($second->refresh()->amount_paid)->toBe('0.0000');
    });

    it('still requires at least one named invoice — a wholly-unallocated receipt is refused', function (): void {
        // Gate-1 #3 — only a remainder on an otherwise-allocated receipt is newly permitted. No pure prepayment.
        $exception = catchPlatformException(
            fn () => app(ReceiptService::class)->record($this->company, new ReceiptData(
                customerId: (string) $this->customer->getKey(),
                receiptDate: CarbonImmutable::parse('2026-06-20'),
                amount: '1000.00',
                paymentMethod: PaymentMethod::BankTransfer,
                bankAccountId: (string) $this->bank->getKey(),
                reference: 'REF-1',
                allocations: [],
            ), $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class)
            ->and($exception->problemCode())->toBe('receipt-has-no-allocations');
    });
});

describe('receipt_held_credits database guards (service bypassed)', function (): void {
    it('refuses driving remaining below zero — the over-consumption backstop', function (): void {
        // ADR 0016 §H / §K#1,#2. The two-layer guard: the row lock produces the readable refusal, this CHECK is
        // what holds if the service is ever bypassed. Mirrors RecordReceiptTest's amount_paid<=total backstop.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        // applied 400 > original 300, remaining -100 — the tie holds, but both the non-negative and the
        // applied≤original CHECKs refuse it.
        expect(fn () => DB::table('receipt_held_credits')->where('id', $held->id)->update([
            'applied_amount' => '400.0000',
            'remaining_amount' => '-100.0000',
        ]))->toThrow(QueryException::class);
    });

    it('refuses breaking the remaining = original − applied tie', function (): void {
        // The analogue of sales_invoices_amount_due_check — remaining and applied can never be written apart.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        // applied moves, remaining does not — the tie is violated.
        expect(fn () => DB::table('receipt_held_credits')->where('id', $held->id)
            ->update(['applied_amount' => '100.0000']))
            ->toThrow(QueryException::class);
    });

    it('refuses a cancelled record that still holds remaining credit', function (): void {
        // §B cancelled_zero_check — a cancelled record holds no usable credit.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        expect(fn () => DB::table('receipt_held_credits')->where('id', $held->id)
            ->update(['status' => 'cancelled']))
            ->toThrow(QueryException::class);
    });

    it('freezes original_amount via the conditional immutability trigger', function (): void {
        // §B — the frozen-column guard: original_amount is history the moment the row exists.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        expect(fn () => DB::table('receipt_held_credits')->where('id', $held->id)
            ->update(['original_amount' => '999.0000']))
            ->toThrow(QueryException::class);
    });

    it('permits applied/remaining/status to move together, the columns apply and cancel need', function (): void {
        // §B — the conditional trigger leaves exactly these writable, the analogue of the invoice trigger
        // leaving amount_paid/amount_due/status writable. Written consistently so the CHECKs also pass.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        DB::table('receipt_held_credits')->where('id', $held->id)->update([
            'applied_amount' => '100.0000',
            'remaining_amount' => '200.0000',
        ]);

        expect(heldCreditFor((string) $receipt->getKey())->applied_amount)->toBe('100.0000')
            ->and(heldCreditFor((string) $receipt->getKey())->remaining_amount)->toBe('200.0000');
    });

    it('refuses deleting a held-credit row outright', function (): void {
        // §B — DELETE is refused; a credit record is unwound by delta, never removed.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');
        $held = heldCreditFor((string) $receipt->getKey());

        expect(fn () => DB::table('receipt_held_credits')->where('id', $held->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses a zero original amount at the database', function (): void {
        // §B original_positive_check — a zero remainder creates no record at all, and the DB backs that.
        // A fully-allocated receipt has no held credit, so its id is free to attach an illegal row to.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect(fn () => DB::table('receipt_held_credits')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_id' => $this->customer->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'currency_code' => $receipt->currency_code,
            'original_amount' => '0.0000',
            'applied_amount' => '0.0000',
            'remaining_amount' => '0.0000',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses an out-of-domain status at the database', function (): void {
        // §B status_check IN ('active','cancelled') — the one-value-widenable device.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect(fn () => DB::table('receipt_held_credits')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->getKey(),
            'customer_id' => $this->customer->getKey(),
            'customer_receipt_id' => $receipt->getKey(),
            'currency_code' => $receipt->currency_code,
            'original_amount' => '100.0000',
            'applied_amount' => '0.0000',
            'remaining_amount' => '100.0000',
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('isolates a held-credit row from another tenant by its own RLS policy', function (): void {
        // §B RLS is not transitive — the table carries its own tenant_id and policy.
        $invoice = holdSuiteInvoice('1000.00');
        $receipt = holdSuiteReceipt([(string) $invoice->getKey() => '700.00'], '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(DB::table('receipt_held_credits')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(DB::table('receipt_held_credits')->where('customer_receipt_id', $receipt->getKey())->exists())
            ->toBeTrue();
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('receipt_held_credits'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role (asids_app).'
    );
});
