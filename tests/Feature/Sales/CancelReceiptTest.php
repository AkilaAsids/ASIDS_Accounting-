<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Authorization\Application\Services\PermissionSynchroniser;
use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\RoleTemplate;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
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
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeCancelled;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a posted customer receipt — ADR 0015 / docs/PHASE-4-CANCELLATION-REQUIREMENTS.md.
 *
 * WRITTEN RED, BEFORE THE FEATURE EXISTS. Authored by QA (Stage 4, test-first), independently of whoever
 * implements it. Every test here references only the INTENDED API that ADR 0015 pins down:
 *
 *   - `ReceiptService::cancel(CustomerReceipt $receipt, string $reason, ?User $actor = null): CustomerReceipt`
 *   - `ReceiptCannotBeCancelled` with the eight named static factories (§B): withoutReason, alreadyCancelled,
 *     notPosted, withoutJournalEntry, journalEntryOutsideCompany, journalEntryNotReversible, intoClosedPeriod,
 *     wouldReverseBelowZero.
 *   - Three new columns on `customer_receipts`: `cancelled_at`, `cancellation_reason`, `cancelled_by_id`.
 *   - The widened `status IN ('posted','cancelled')` CHECK, the tie-to-status CHECK, and the two new trigger
 *     guards (finality + transition-scoped metadata) on `asids_customer_receipts_immutable()`.
 *   - The `sales.receipts.cancel` capability and `CustomerReceiptPolicy::cancel()`.
 *
 * THE PROBLEM-CODE CONTRACT (the spec the engineer builds to)
 * ----------------------------------------------------------
 * ADR 0015 §B names the factories but not their stable `problemCode()` strings. This file fixes them, mirroring
 * `InvoiceCannotBeCancelled`'s codes with a `receipt-` prefix (the receipt family's convention — see
 * `ReceiptCannotBeRecorded`/`ReceiptCannotBeAllocated`). The engineer must make each factory emit these:
 *
 *   withoutReason               → 'receipt-cancellation-reason-required'
 *   alreadyCancelled            → 'receipt-already-cancelled'
 *   notPosted                   → 'receipt-not-posted'
 *   withoutJournalEntry         → 'receipt-journal-entry-missing'
 *   journalEntryOutsideCompany  → 'receipt-journal-entry-outside-company'
 *   journalEntryNotReversible   → 'receipt-journal-entry-not-reversible'
 *   intoClosedPeriod            → 'receipt-reversal-period-not-open'
 *   wouldReverseBelowZero       → 'receipt-would-reverse-below-zero'
 *
 * WHY IT FAILS RED, AND FOR THE RIGHT REASON
 * ------------------------------------------
 * Setup runs through the shipped, working services (`ReceiptService::record()`, `SalesInvoiceService::issue()`),
 * so a red failure points at the absent cancellation feature, never at a broken fixture. Service-level tests
 * error on `Call to undefined method ReceiptService::cancel()`; catalogue/policy tests fail because
 * `sales.receipts.cancel` is not defined; DB-level tests fail their `hasColumn(...)` precondition because the
 * migration has not run. A handful of tests are REGRESSION GUARDS that are green from the start by design (they
 * assert existing RLS / allocation-freeze behaviour still holds once the wave lands) and are labelled as such.
 *
 * Dates are frozen so the reversal's period and "dated today" are deterministic, mirroring `CancelInvoiceTest`.
 * Invoices are dated 2026-06-10, receipts 2026-06-15, and "today" is 2026-06-20 — three distinct dates so the
 * "mirror is dated today, not the receipt_date" assertion has teeth.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-20 09:00:00'));

    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);
    $this->invoices = app(SalesInvoiceService::class);

    $this->revenue = cancelSuiteAccount('4100');
    $this->receivables = cancelSuiteAccount('1130');
    $this->bank = cancelSuiteAccount('1120');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

/**
 * An account of this suite's company (or a named other company) by code.
 */
function cancelSuiteAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * An issued invoice for the suite's customer, real figures and a real posting.
 */
function cancelSuiteInvoice(string $unitPrice = '1000.00', string $date = '2026-06-10', ?string $customerId = null): SalesInvoice
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
 * A posted, fully-allocated receipt for the suite's customer, built through the shipped `record()` path.
 *
 * @param  array<string, string>  $allocations  [invoiceId => amount]
 */
function cancelSuiteReceipt(
    array $allocations,
    string $amount,
    string $date = '2026-06-15',
    ?string $customerId = null,
    ?string $bankAccountId = null,
    string $reference = 'REF-1',
): CustomerReceipt {
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: PaymentMethod::BankTransfer,
        bankAccountId: $bankAccountId ?? (string) test()->bank->getKey(),
        reference: $reference,
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
 * A member of the acme company holding the given system role.
 */
function cancelSuiteMemberWithRole(string $role, string $email): User
{
    $user = test()->createUserWithRole(test()->acme['tenant'], $role, ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);

    return RowLevelSecurity::bypass(static fn () => $user->fresh());
}

/**
 * Runs a callback with a named immutability trigger suspended, restored in a `finally`.
 *
 * Only for planting states the triggers otherwise make unreachable, so a defensive service branch or a
 * database CHECK can be exercised in isolation — mirrors `CancelInvoiceTest`'s `withoutImmutability()`.
 */
function suspendReceiptTrigger(string $table, string $trigger, Closure $callback): void
{
    DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");

    try {
        $callback();
    } finally {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }
}

describe('the ledger reversal', function (): void {
    it('reverses the receipt posting, marking the original Reversed and pointing at its mirror', function (): void {
        // AC-C1.1, AC-C1.3, AC-C1.4
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');
        $originalEntryId = $receipt->journal_entry_id;

        $this->receipts->cancel($receipt, 'Recorded against the wrong invoice', $this->owner);

        $original = JournalEntry::query()->findOrFail($originalEntryId);
        $mirror = JournalEntry::query()->findOrFail($original->reversed_by_entry_id);

        expect($original->status)->toBe(JournalEntryStatus::Reversed)
            ->and($original->reversal_reason)->toBe('Recorded against the wrong invoice')
            ->and($original->reversed_at)->not->toBeNull()
            ->and($mirror->reverses_entry_id)->toBe($original->getKey())
            // The mirror cites the same receipt, so it is traceable through the source index that excludes
            // reversing entries (AC-C1.3).
            ->and($mirror->source_id)->toBe((string) $receipt->getKey())
            ->and($mirror->source_type)->toBe(CustomerReceipt::MORPH_ALIAS)
            ->and($mirror->company_id)->toBe($original->company_id)
            ->and($mirror->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('dates the mirror at today, the reversal date, not the receipt date', function (): void {
        // AC-C1.1 — the "which period must be open is the reversal's, not the document's" rule made observable.
        $invoice = cancelSuiteInvoice('1000.00', date: '2026-06-10');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00', date: '2026-06-15');

        $this->receipts->cancel($receipt, 'Duplicate receipt', $this->owner);

        $mirror = JournalEntry::query()->findOrFail(
            JournalEntry::query()->findOrFail($receipt->journal_entry_id)->reversed_by_entry_id
        );

        expect($mirror->entry_date->toDateString())->toBe('2026-06-20')
            ->and($mirror->entry_date->toDateString())->not->toBe('2026-06-15');
    });

    it('mirrors every line with its side swapped so the pair nets to nothing', function (): void {
        // AC-C1.1 — amounts copied, sides swapped, the two entries net to zero.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($receipt, 'Wrong customer', $this->owner);

        $original = JournalEntry::query()->findOrFail($receipt->journal_entry_id);
        $mirror = JournalEntry::query()->findOrFail($original->reversed_by_entry_id);

        $sum = fn (JournalEntry $entry, string $side): string => (string) DB::table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())->sum($side);

        expect($sum($mirror, 'debit'))->toBe($sum($original, 'credit'))
            ->and($sum($mirror, 'credit'))->toBe($sum($original, 'debit'))
            ->and($sum($mirror, 'debit'))->toBe($sum($mirror, 'credit'))
            ->and($mirror->lines()->count())->toBe($original->lines()->count());
    });
});

describe('numbering: the RCT is retained, a fresh JV is consumed', function (): void {
    it('keeps the receipt number and its counter, drawing only a new JV for the reversal', function (): void {
        // AC-C1.2
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $before = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $after = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        expect($receipt->refresh()->number)->toBe('RCT-2026-06-0001')
            // The receipt counter did not move: cancellation consumes no RCT number.
            ->and((int) $after[DocumentType::CustomerReceipt->value])
            ->toBe((int) $before[DocumentType::CustomerReceipt->value])
            // Exactly one new journal voucher — the reversal.
            ->and((int) $after[DocumentType::JournalVoucher->value])
            ->toBe((int) $before[DocumentType::JournalVoucher->value] + 1);
    });

    it('numbers the reversal from the journal voucher series', function (): void {
        // AC-C1.2 — the mirror reuses the original's document type, so it draws a JV, not an RCT.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $mirror = JournalEntry::query()->findOrFail(
            JournalEntry::query()->findOrFail($receipt->journal_entry_id)->reversed_by_entry_id
        );

        expect($mirror->document_type)->toBe(DocumentType::JournalVoucher)
            ->and($mirror->number)->toStartWith('JV-2026-06-');
    });

    it('advances the JV series across a cancel while the RCT series stays gapless', function (): void {
        // AC-C1.2 — the multi-event assertion: a cancellation must not push the next receipt one further along.
        $first = cancelSuiteInvoice('1000.00');
        $r1 = cancelSuiteReceipt([(string) $first->getKey() => '1000.00'], '1000.00');

        $second = cancelSuiteInvoice('1000.00');
        $r2 = cancelSuiteReceipt([(string) $second->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($r1, 'Recorded in error', $this->owner);

        $third = cancelSuiteInvoice('1000.00');
        $r3 = cancelSuiteReceipt([(string) $third->getKey() => '1000.00'], '1000.00');

        // 0001, 0002, 0003 — the cancel between r2 and r3 burned no RCT number.
        expect($r1->refresh()->number)->toBe('RCT-2026-06-0001')
            ->and($r2->refresh()->number)->toBe('RCT-2026-06-0002')
            ->and($r3->refresh()->number)->toBe('RCT-2026-06-0003');
    });
});

describe('restoring each allocated invoice by delta', function (): void {
    it('restores a fully paid invoice to Issued when its only receipt is cancelled', function (): void {
        // AC-C2.3
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Paid);

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $invoice->refresh();

        expect($invoice->amount_paid)->toBe('0.0000')
            ->and($invoice->amount_due)->toBe('1000.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Issued);
    });

    it('restores a partially paid invoice to Issued when its only receipt is cancelled', function (): void {
        // AC-C2.4
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '400.00'], '400.00');

        expect($invoice->refresh()->status)->toBe(SalesInvoiceStatus::PartiallyPaid);

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $invoice->refresh();

        expect($invoice->amount_paid)->toBe('0.0000')
            ->and($invoice->amount_due)->toBe('1000.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Issued);
    });

    it('subtracts only this receipts contribution, leaving a later receipts payment intact', function (): void {
        // AC-C2.6 (the case that proves AC-C2.1's delta rule) + AC-C2.1.
        $invoice = cancelSuiteInvoice('1000.00');

        $receiptA = cancelSuiteReceipt([(string) $invoice->getKey() => '400.00'], '400.00', date: '2026-06-15');
        $receiptB = cancelSuiteReceipt([(string) $invoice->getKey() => '600.00'], '600.00', date: '2026-06-16');

        expect($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($invoice->amount_paid)->toBe('1000.0000');

        $this->receipts->cancel($receiptA, 'Receipt A was recorded in error', $this->owner);

        $invoice->refresh();

        // 1000 - 400 = 600, NOT 0 (which would silently erase B) and NOT left at Paid.
        expect($invoice->amount_paid)->toBe('600.0000')
            ->and($invoice->amount_due)->toBe('400.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::PartiallyPaid)
            // B is untouched: still posted, its ledger entry still Posted (never reversed).
            ->and($receiptB->refresh()->status)->toBe('posted')
            ->and(JournalEntry::query()->findOrFail($receiptB->journal_entry_id)->status)
            ->toBe(JournalEntryStatus::Posted);
    });

    it('restores two invoices independently, each from its own allocation row', function (): void {
        // AC-C2.5
        $small = cancelSuiteInvoice('300.00');
        $large = cancelSuiteInvoice('900.00');

        $receipt = cancelSuiteReceipt([
            (string) $small->getKey() => '300.00',
            (string) $large->getKey() => '700.00',
        ], '1000.00');

        expect($small->refresh()->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($large->refresh()->status)->toBe(SalesInvoiceStatus::PartiallyPaid);

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $small->refresh();
        $large->refresh();

        expect($small->amount_paid)->toBe('0.0000')
            ->and($small->amount_due)->toBe('300.0000')
            ->and($small->status)->toBe(SalesInvoiceStatus::Issued)
            ->and($large->amount_paid)->toBe('0.0000')
            ->and($large->amount_due)->toBe('900.0000')
            ->and($large->status)->toBe(SalesInvoiceStatus::Issued);
    });

    it('refuses when the reversal would drive an invoice below zero', function (): void {
        // AC-C2.7 — the defensive negative-balance guard. The invoice's amount_paid is lowered below this
        // receipt's own allocation (a state only a bug could reach), so the delta would go negative.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // amount_paid / amount_due / status are the invoice trigger's permitted mutable columns, so this needs
        // no trigger suspension — it plants a below-allocation balance the arithmetic cannot legitimately reach.
        DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'amount_paid' => '500.0000',
            'amount_due' => '500.0000',
            'status' => SalesInvoiceStatus::PartiallyPaid->value,
        ]);

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Recorded in error', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-would-reverse-below-zero')
            // The whole transaction rolled back: no reversal survives.
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });
});

describe('cancellation metadata', function (): void {
    it('records who cancelled it, when and why, leaving the receipt header otherwise untouched', function (): void {
        // AC-C3.1
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');
        $originalEntryId = $receipt->journal_entry_id;

        $cancelled = $this->receipts->cancel($receipt, 'Customer stopped the cheque', $this->owner);

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->cancellation_reason)->toBe('Customer stopped the cheque')
            ->and((string) $cancelled->cancelled_by_id)->toBe((string) $this->owner->getKey())
            // Everything the receipt carried is retained — cancelling is not an edit.
            ->and($cancelled->number)->toBe('RCT-2026-06-0001')
            ->and($cancelled->amount)->toBe('1000.0000')
            ->and($cancelled->receipt_date->toDateString())->toBe('2026-06-15')
            ->and($cancelled->journal_entry_id)->toBe($originalEntryId)
            ->and($cancelled->posted_at)->not->toBeNull();
    });
});

describe('what cancelling refuses', function (): void {
    it('refuses a blank or whitespace-only reason before any lock or number', function (): void {
        // AC-C1.5, AC-C3.2, AC-C4.8
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt, '   ', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-cancellation-reason-required')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0)
            ->and($receipt->refresh()->status)->toBe('posted');
    });

    it('refuses a receipt that is already cancelled', function (): void {
        // AC-C4.1, AC-C6.3 (readable check)
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($receipt, 'First cancellation', $this->owner);

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Second cancellation', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-already-cancelled')
            // One reversal, not two.
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(1);
    });

    it('refuses a receipt whose status is not posted, defensively', function (): void {
        // AC-C4.2 — a dead-code-by-design branch written now (Gate-1 #5). No third status is reachable under
        // the two-value CHECK, so the state is planted by dropping that CHECK and suspending the trigger.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        DB::statement('ALTER TABLE customer_receipts DROP CONSTRAINT IF EXISTS customer_receipts_status_check');
        suspendReceiptTrigger('customer_receipts', 'customer_receipts_immutable', function () use ($receipt): void {
            DB::table('customer_receipts')->where('id', $receipt->getKey())->update(['status' => 'draft']);
        });

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Recorded in error', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-not-posted');
    });

    it('refuses a receipt that names no journal entry', function (): void {
        // AC-C4.4 — an unreachable state through supported paths; planted with the trigger suspended.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        suspendReceiptTrigger('customer_receipts', 'customer_receipts_immutable', function () use ($receipt): void {
            DB::table('customer_receipts')->where('id', $receipt->getKey())->update(['journal_entry_id' => null]);
        });

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'No entry', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-journal-entry-missing');
    });

    it('refuses when the journal entry belongs to a sibling company, distinct from RLS', function (): void {
        // AC-C4.4 — proven distinct from RLS: the sibling company shares this tenant, so row level security is
        // satisfied by its entries; only the explicit company comparison in the service refuses it.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        suspendReceiptTrigger('journal_entries', 'journal_entries_immutable', function () use ($receipt, $second): void {
            DB::table('journal_entries')->where('id', $receipt->journal_entry_id)
                ->update(['company_id' => $second->getKey()]);
        });

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Wrong company', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-journal-entry-outside-company');
    });

    it('refuses when the journal entry has already been reversed', function (): void {
        // AC-C4.4 — comparing to Posted explicitly excludes an already-reversed entry, so the caller hears
        // about the receipt rather than a silent second reversal.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        app(PostingService::class)->reverse(
            JournalEntry::query()->findOrFail($receipt->journal_entry_id),
            'Reversed outside the sales module',
            CarbonImmutable::parse('2026-06-20'),
            $this->owner,
        );

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Cancel it too', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-journal-entry-not-reversible');
    });

    it('refuses cancelling into a closed reversal period, before any number is reserved', function (): void {
        // AC-C1.6, AC-C4.3 — it is the reversal date's period (today, June) that must be open, not the
        // receipt_date's.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        FiscalPeriod::query()->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-20'))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt->refresh(), 'Too late', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-reversal-period-not-open')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });
});

describe('receipt allocations are permanent history', function (): void {
    it('leaves every allocation row byte-identical after cancellation', function (): void {
        // AC-C3.3 — cancellation reads allocation rows, never writes them.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $before = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())->orderBy('id')->get()->toArray();

        $this->receipts->cancel($receipt, 'Recorded in error', $this->owner);

        $after = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())->orderBy('id')->get()->toArray();

        expect($after)->not->toBeEmpty()->and($after)->toEqual($before);
    });
});

describe('permission and policy', function (): void {
    it('declares sales.receipts.cancel as a distinct, sensitive capability', function (): void {
        // AC-C5.1 — a second capability, separate from sales.receipts.manage.
        $definitions = [];

        foreach (PermissionCatalogue::all() as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        expect($definitions)->toHaveKey('sales.receipts.cancel')
            ->and($definitions)->toHaveKey('sales.receipts.manage')
            // Distinct capabilities, not one riding on the other.
            ->and('sales.receipts.cancel')->not->toBe('sales.receipts.manage')
            // Sensitive: it moves money and posts a reversal.
            ->and($definitions['sales.receipts.cancel']->sensitive)->toBeTrue();
    });

    it('grants sales.receipts.cancel to exactly the accountant and administrator', function (): void {
        // AC-C5.3
        $holders = collect(RoleTemplate::all())
            ->reject(static fn (RoleTemplate $t): bool => $t->isOwner)
            ->filter(static fn (RoleTemplate $t): bool => in_array('sales.receipts.cancel', $t->permissions, true))
            ->map(static fn (RoleTemplate $t): string => $t->name)
            ->values()
            ->all();

        expect($holders)->toBe(['administrator', 'accountant']);
    });

    it('does not grant sales.receipts.cancel to the bookkeeper or viewer', function (): void {
        // AC-C5.3 — the same split as invoice cancel: committing a reversal is not day-to-day work.
        $bookkeeper = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'bookkeeper',
        );
        $viewer = collect(RoleTemplate::all())->firstOrFail(
            static fn (RoleTemplate $t): bool => $t->name === 'viewer',
        );

        expect($bookkeeper->permissions)->not->toContain('sales.receipts.cancel')
            ->and($viewer->permissions)->not->toContain('sales.receipts.cancel');
    });

    it('synchronises sales.receipts.cancel into a workspace that predates it', function (): void {
        // AC-C5.1 / ADR 0015 §D — a code-defined permission an existing workspace picks up on sync.
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->where('name', 'sales.receipts.cancel')->delete();
        });

        RowLevelSecurity::bypass(static fn (): array => app(PermissionSynchroniser::class)->sync());

        expect(RowLevelSecurity::bypass(static fn (): bool => DB::table('permissions')
            ->where('name', 'sales.receipts.cancel')
            ->where('is_sensitive', true)
            ->exists()))->toBeTrue();
    });

    it('lets an accountant cancel a posted receipt through the gate and the service', function (): void {
        // AC-C5.2, AC-C5.3 — the capability is load-bearing, not just a string.
        $accountant = cancelSuiteMemberWithRole('accountant', 'cxl-acct@acme.test');
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect($accountant->can('sales.receipts.cancel'))->toBeTrue()
            ->and($accountant->can('cancel', $receipt))->toBeTrue();

        $cancelled = $this->receipts->cancel($receipt, 'Recorded in error', $accountant);

        expect($cancelled->status)->toBe('cancelled');
    });

    it('requires sales.receipts.cancel specifically, not sales.receipts.manage', function (): void {
        // AC-C5.1 — the policy's cancel() must check the cancel capability, never let it ride on manage.
        // The permission is removed BEFORE the accountant is created, so there is no stale permission cache.
        RowLevelSecurity::bypass(static function (): void {
            DB::table('permissions')->where('name', 'sales.receipts.cancel')->delete();
        });

        $accountant = cancelSuiteMemberWithRole('accountant', 'cxl-manage-only@acme.test');
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect($accountant->can('sales.receipts.manage'))->toBeTrue()
            ->and($accountant->can('sales.receipts.cancel'))->toBeFalse()
            ->and($accountant->can('cancel', $receipt))->toBeFalse();
    });

    it('lets the tenant owner pass the gate yet the service still enforces the state rules', function (): void {
        // AC-C5.2 — Gate::before short-circuits the policy for the owner, so ReceiptService stays the boundary.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // The gate says yes for the owner even though the policy is advisory only.
        expect($this->owner->can('cancel', $receipt))->toBeTrue();

        // The service still refuses a blank reason.
        $exception = catchPlatformException(
            fn () => $this->receipts->cancel($receipt, '   ', $this->owner)
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('receipt-cancellation-reason-required');
    });
});

describe('tenant isolation', function (): void {
    it('cannot be cancelled from another tenant', function (): void {
        // NFR RLS scope — a second tenant cannot drive this receipt's cancellation; RLS hides the row from the
        // service's own re-read.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(fn () => $this->receipts->cancel($receipt, 'From the wrong tenant', $other['owner']))
            ->toThrow(ModelNotFoundException::class);

        $this->withinTenant($this->acme['tenant']);

        expect($receipt->refresh()->status)->toBe('posted');
    });

    it('keeps a posted receipt invisible from another tenant [regression guard: existing RLS]', function (): void {
        // NFR RLS scope — green from the start; guards that the new columns/trigger do not break isolation.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        expect(CustomerReceipt::query()->whereKey($receipt->getKey())->exists())->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(CustomerReceipt::query()->whereKey($receipt->getKey())->exists())->toBeTrue();
    });
});

describe('database-level immutability, service bypassed', function (): void {
    it('refuses the cancelled status without its metadata, via the tie-to-status CHECK', function (): void {
        // NFR conditional immutability — status='cancelled' with null metadata cannot exist. The trigger
        // permits this column shape, so the CHECK is the mechanism that refuses it.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // RED precondition: the migration must have added the metadata columns. Fails here (not on a stray
        // "column does not exist") until the schema lands.
        expect(DB::getSchemaBuilder()->hasColumn('customer_receipts', 'cancelled_at'))->toBeTrue();

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())
            ->update(['status' => 'cancelled']))
            ->toThrow(QueryException::class);
    });

    it('refuses cancellation metadata on a receipt that is not being cancelled, via the trigger', function (): void {
        // NFR conditional immutability — the transition-scoped metadata guard: metadata may change only on an
        // update where NEW.status = 'cancelled'.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect(DB::getSchemaBuilder()->hasColumn('customer_receipts', 'cancellation_reason'))->toBeTrue();

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())
            ->update(['cancellation_reason' => 'Never actually cancelled']))
            ->toThrow(QueryException::class);
    });

    it('refuses orphan metadata even with the trigger suspended, via the CHECK', function (): void {
        // NFR conditional immutability — isolates the tie-to-status CHECK from the trigger. The throwing update
        // is wrapped in its own transaction so the CHECK violation rolls back to a savepoint and the outer
        // (RefreshDatabase) transaction survives for the finally's re-enable.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect(DB::getSchemaBuilder()->hasColumn('customer_receipts', 'cancellation_reason'))->toBeTrue();

        suspendReceiptTrigger('customer_receipts', 'customer_receipts_immutable', function () use ($receipt): void {
            expect(fn () => DB::transaction(fn () => DB::table('customer_receipts')
                ->where('id', $receipt->getKey())
                ->update(['cancellation_reason' => 'Orphaned metadata, no cancelled status'])))
                ->toThrow(QueryException::class);
        });
    });

    it('refuses any further update once the receipt is cancelled, via the finality guard', function (): void {
        // AC-C6.3 — a cancelled receipt is frozen as hard as a posted one, in its own terminal state.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($receipt, 'First cancellation', $this->owner);

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())
            ->update(['cancellation_reason' => 'Rewritten after the fact']))
            ->toThrow(QueryException::class);
    });

    it('refuses reverting a cancelled receipt to posted, via the finality guard', function (): void {
        // AC-C6.3 — no un-cancel.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $this->receipts->cancel($receipt, 'First cancellation', $this->owner);

        expect(fn () => DB::table('customer_receipts')->where('id', $receipt->getKey())
            ->update(['status' => 'posted']))
            ->toThrow(QueryException::class);
    });

    it('still refuses updating a receipt allocation row [regression guard: existing freeze]', function (): void {
        // AC-C3.3 — green from the start; guards that this wave did not relax the allocations full-freeze.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $allocationId = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())->value('id');

        expect(fn () => DB::table('receipt_allocations')->where('id', $allocationId)
            ->update(['amount' => '1.0000']))
            ->toThrow(QueryException::class);
    });

    it('still refuses deleting a receipt allocation row [regression guard: existing freeze]', function (): void {
        // AC-C3.3 — green from the start; the historical trail cannot be deleted on cancel.
        $invoice = cancelSuiteInvoice('1000.00');
        $receipt = cancelSuiteReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $allocationId = DB::table('receipt_allocations')
            ->where('customer_receipt_id', $receipt->getKey())->value('id');

        expect(fn () => DB::table('receipt_allocations')->where('id', $allocationId)->delete())
            ->toThrow(QueryException::class);
    });
});
