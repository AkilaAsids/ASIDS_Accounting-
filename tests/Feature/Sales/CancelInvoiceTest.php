<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBeCancelled;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancelling: undoing an invoice without pretending it never existed.
 *
 * Stage 4 of Milestone 5. A cancellation is not a deletion and not an edit. The invoice keeps its number, its
 * dates and every figure on it; its original posting stays in the ledger; and a mirror entry is written
 * alongside. What an auditor sees afterwards is the document, the posting, and the correction — three records
 * that agree. Delete any of them and the series has a hole nobody can explain.
 *
 * Two groups earn their place beyond the happy path.
 *
 * **Numbering.** Cancelling must not consume a sales invoice number. It does not, because Stage 3 typed the
 * invoice's ledger entry as `JournalVoucher` and `PostingService::reverse()` copies the original's document
 * type — so the mirror draws from the journal voucher counter. Had Stage 3 typed the entry `SalesInvoice`,
 * every cancellation would silently push the next invoice one further along, and no single-invoice test would
 * notice. The test below issues, cancels and issues again for exactly that reason.
 *
 * **What survives a failure.** A reversal that commits without the invoice being cancelled is worse than a
 * failed cancellation: the ledger says the revenue is gone while the invoice still claims it is owed.
 */
beforeEach(function (): void {
    // Frozen, because both `cancel()` and `PostingService::reverse()` date the reversal from `now()`. Without
    // this the period a reversal lands in depends on the day the suite runs.
    $this->travelTo(CarbonImmutable::parse('2026-06-20 09:00:00'));

    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->invoices = app(SalesInvoiceService::class);

    $this->revenue = cancellingAccount('4100');
    $this->receivables = cancellingAccount('1130');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function cancellingAccount(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * Runs a callback with an immutability trigger suspended.
 *
 * Only for planting states the triggers make unreachable, so a defensive service check can be exercised at
 * all. That these states need the protection switched off is itself worth noticing: each one is a branch the
 * application cannot reach, kept as depth rather than as a live path. Restored in a `finally`, so a failing
 * assertion inside cannot leave the protection off for the rest of the suite.
 */
function withoutImmutability(string $table, string $trigger, Closure $callback): void
{
    DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");

    try {
        $callback();
    } finally {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }
}

/**
 * An issued invoice, built and posted through the services so every figure and link is real.
 */
function cancellableInvoice(string $unitPrice = '1000.00', string $date = '2026-06-15'): SalesInvoice
{
    $draft = app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
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

describe('cancelling an issued invoice', function (): void {
    it('reverses its posting and records why', function (): void {
        $invoice = cancellableInvoice();
        $originalEntryId = $invoice->journal_entry_id;

        $cancelled = $this->invoices->cancel($invoice, 'Customer cancelled the order', $this->owner);

        expect($cancelled->status)->toBe(SalesInvoiceStatus::Cancelled)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->cancellation_reason)->toBe('Customer cancelled the order')
            ->and((string) $cancelled->cancelled_by_id)->toBe((string) $this->owner->getKey())
            // Untouched: the customer holds a document carrying all of this.
            ->and($cancelled->number)->toBe('INV-2026-06-0001')
            ->and($cancelled->issued_at)->not->toBeNull()
            ->and($cancelled->journal_entry_id)->toBe($originalEntryId)
            ->and($cancelled->total)->toBe($invoice->total)
            ->and($cancelled->subtotal)->toBe($invoice->subtotal)
            ->and($cancelled->tax_total)->toBe($invoice->tax_total);
    });

    it('leaves the original entry reversed and pointing at its mirror', function (): void {
        $invoice = cancellableInvoice();

        $this->invoices->cancel($invoice, 'Duplicate invoice', $this->owner);

        $original = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
        $reversal = JournalEntry::query()->findOrFail($original->reversed_by_entry_id);

        expect($original->status)->toBe(JournalEntryStatus::Reversed)
            ->and($original->reversal_reason)->toBe('Duplicate invoice')
            ->and($original->reversed_at)->not->toBeNull()
            ->and($reversal->reverses_entry_id)->toBe($original->getKey())
            // The reversal cites the same invoice, which is how it is found without a column on the invoice
            // pointing at it — approved decision B3.
            ->and($reversal->source_id)->toBe((string) $invoice->getKey())
            ->and($reversal->source_type)->toBe(SalesInvoice::MORPH_ALIAS)
            ->and($reversal->company_id)->toBe($original->company_id);
    });

    it('mirrors the original exactly, so the two net to nothing', function (): void {
        $invoice = cancellableInvoice('1000.00');

        $this->invoices->cancel($invoice, 'Wrong customer', $this->owner);

        $original = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
        $reversal = JournalEntry::query()->findOrFail($original->reversed_by_entry_id);

        $sum = fn (JournalEntry $entry, string $side): string => (string) DB::table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())->sum($side);

        // Debits and credits swap, and the totals match, so the pair leaves every account where it started.
        expect($sum($reversal, 'debit'))->toBe($sum($original, 'credit'))
            ->and($sum($reversal, 'credit'))->toBe($sum($original, 'debit'))
            ->and($sum($reversal, 'debit'))->toBe($sum($reversal, 'credit'))
            ->and($reversal->lines()->count())->toBe($original->lines()->count());
    });

    it('finds the reversal through the ledger rather than a column on the invoice', function (): void {
        $invoice = cancellableInvoice();

        $this->invoices->cancel($invoice, 'Order withdrawn', $this->owner);

        // B3: no `reversal_journal_entry_id`. Both routes below already exist, which is why the column was
        // refused.
        $viaEntry = JournalEntry::query()->findOrFail($invoice->journal_entry_id)->reversed_by_entry_id;

        $viaSource = JournalEntry::query()
            ->where('source_type', SalesInvoice::MORPH_ALIAS)
            ->where('source_id', (string) $invoice->getKey())
            ->whereNotNull('reverses_entry_id')
            ->value('id');

        expect($viaEntry)->not->toBeNull()->and($viaSource)->toBe($viaEntry)
            ->and(DB::getSchemaBuilder()->hasColumn('sales_invoices', 'reversal_journal_entry_id'))->toBeFalse();
    });
});

describe('the invoice number series', function (): void {
    it('is not consumed by a cancellation', function (): void {
        $first = cancellableInvoice();
        $second = cancellableInvoice();

        $this->invoices->cancel($first, 'Cancelled by customer', $this->owner);

        $third = cancellableInvoice();

        // 0003, not 0004. The whole reason Stage 3 split the counters: had the reversal drawn from the
        // sales invoice series, this would be 0004 and the series would carry a permanent gap.
        expect($first->number)->toBe('INV-2026-06-0001')
            ->and($second->number)->toBe('INV-2026-06-0002')
            ->and($third->number)->toBe('INV-2026-06-0003');
    });

    it('numbers the reversal from the journal voucher series', function (): void {
        $first = cancellableInvoice();
        $second = cancellableInvoice();

        $this->invoices->cancel($first, 'Cancelled by customer', $this->owner);

        $reversal = JournalEntry::query()
            ->findOrFail(JournalEntry::query()->findOrFail($first->journal_entry_id)->reversed_by_entry_id);

        // Two invoices posted JV-0001 and JV-0002; the reversal is the third journal voucher.
        expect($reversal->number)->toBe('JV-2026-06-0003')
            ->and($reversal->document_type)->toBe(DocumentType::JournalVoucher);
    });

    it('leaves the two counters where they should be', function (): void {
        $invoice = cancellableInvoice();

        $this->invoices->cancel($invoice, 'Cancelled by customer', $this->owner);

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        // One invoice issued, so the invoice counter sits at 2 and cancellation did not move it. Two journal
        // vouchers — the posting and its reversal — so that counter sits at 3.
        expect((int) $sequences[DocumentType::SalesInvoice->value])->toBe(2)
            ->and((int) $sequences[DocumentType::JournalVoucher->value])->toBe(3);
    });
});

describe('what cancelling refuses', function (): void {
    it('refuses a draft', function (): void {
        $draft = app(SalesInvoiceService::class)->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting services',
                quantity: '1',
                unitPrice: '1000.00',
                revenueAccountId: (string) $this->revenue->getKey(),
            )],
        ));

        $exception = catchPlatformException(fn () => $this->invoices->cancel($draft, 'Not wanted', $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBeCancelled::class)
            ->and($exception->problemCode())->toBe('invoice-not-issued')
            ->and(JournalEntry::query()->count())->toBe(0);
    });

    it('refuses an invoice that is already cancelled', function (): void {
        $invoice = cancellableInvoice();
        $this->invoices->cancel($invoice, 'First cancellation', $this->owner);

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'Second cancellation', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-already-cancelled')
            // One reversal, not two.
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(1);
    });

    it('refuses when the invoice names no ledger entry', function (): void {
        $invoice = cancellableInvoice();

        // The immutability trigger has to be suspended to reach this state at all, which is itself the finding:
        // an issued invoice without an entry is unreachable through any supported path. The service check is
        // depth rather than a live branch, and planting the state explicitly is the only honest way to prove it
        // behaves.
        withoutImmutability('sales_invoices', 'sales_invoices_immutable', function () use ($invoice): void {
            DB::table('sales_invoices')->where('id', $invoice->getKey())->update(['journal_entry_id' => null]);
        });

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'No entry', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-journal-entry-missing');
    });

    it('refuses when the ledger entry belongs to another company', function (): void {
        $invoice = cancellableInvoice();

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);
        app(ChartTemplateService::class)->apply($other['company']);
        $this->withinTenant($this->acme['tenant']);

        // The entry is moved rather than the invoice repointed, because the invoice's `journal_entry_id` is
        // frozen once issued. Row level security is satisfied either way — both companies share a tenant — so
        // only the explicit company comparison in the service refuses it.
        withoutImmutability('journal_entries', 'journal_entries_immutable', function () use ($invoice, $other): void {
            DB::table('journal_entries')->where('id', $invoice->journal_entry_id)
                ->update(['company_id' => $other['company']->getKey()]);
        });

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'Wrong company', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-journal-entry-outside-company');
    });

    it('refuses when the ledger entry has already been reversed', function (): void {
        $invoice = cancellableInvoice();

        // Reversed directly in Accounting, leaving the invoice still marked issued — the state a partially
        // applied cancellation would leave behind.
        app(PostingService::class)->reverse(
            JournalEntry::query()->findOrFail($invoice->journal_entry_id),
            'Reversed outside the sales module',
            CarbonImmutable::parse('2026-06-20'),
            $this->owner,
        );

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'Cancel it too', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-journal-entry-not-reversible');
    });

    it('refuses when the period the reversal would land in is closed', function (): void {
        $invoice = cancellableInvoice();

        // June is where *today* falls, so this is the reversal's period rather than the invoice's — the
        // distinction the approved decision turns on.
        // `closed_at` moves with the status: `fiscal_periods_closed_check` asserts `(status = 'open') =
        // (closed_at IS NULL)`.
        FiscalPeriod::query()->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-20'))
            ->update(['status' => PeriodStatus::Closed->value, 'closed_at' => now()]);

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'Too late', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-reversal-period-not-open')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });

    it('refuses an invoice with money already received against it', function (): void {
        $invoice = cancellableInvoice();

        // Phase 4 territory: `amount_paid` is held at zero by a phase-scoped CHECK, so the column has to be
        // moved with the constraint lifted. The rule is asserted now so it exists before payments do.
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT sales_invoices_no_payments_until_payments_phase');
        DB::table('sales_invoices')->where('id', $invoice->getKey())
            ->update(['amount_paid' => '400.0000', 'amount_due' => bcsub($invoice->total, '400.0000', 4)]);

        $exception = catchPlatformException(
            fn () => $this->invoices->cancel($invoice->refresh(), 'Cancel despite payment', $this->owner)
        );

        expect($exception->problemCode())->toBe('invoice-partially-paid')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });

    it('refuses a blank reason, because the ledger records it', function (): void {
        $invoice = cancellableInvoice();

        $exception = catchPlatformException(fn () => $this->invoices->cancel($invoice, '   ', $this->owner));

        expect($exception->problemCode())->toBe('invoice-cancellation-reason-required')
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
    });
});

describe('a failed cancellation leaves nothing behind', function (): void {
    it('rolls the reversal back when the invoice cannot be saved', function (): void {
        $invoice = cancellableInvoice();

        // Fails *after* `reverse()` has posted its mirror and taken a journal voucher number, which is the
        // case worth proving: not the refusal, the rollback. A model event is the seam because it needs no
        // production-only branch.
        SalesInvoice::updating(static function (): void {
            throw new RuntimeException('Simulated failure while writing the cancellation');
        });

        expect(fn () => $this->invoices->cancel($invoice, 'Will not commit', $this->owner))
            ->toThrow(RuntimeException::class);

        $after = DB::table('sales_invoices')->where('id', $invoice->getKey())->first();
        $original = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        expect($after->status)->toBe('issued')
            ->and($after->cancelled_at)->toBeNull()
            ->and($after->cancellation_reason)->toBeNull()
            ->and($after->cancelled_by_id)->toBeNull()
            ->and($after->number)->toBe('INV-2026-06-0001')
            // The original is still posted, and no mirror survives.
            ->and($original->status)->toBe(JournalEntryStatus::Posted)
            ->and($original->reversed_by_entry_id)->toBeNull()
            ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0)
            // The journal voucher number the reversal took was returned: still 2, from the invoice posting.
            ->and((int) $sequences[DocumentType::JournalVoucher->value])->toBe(2)
            ->and((int) $sequences[DocumentType::SalesInvoice->value])->toBe(2);
    });
});

describe('cancelling the same invoice twice', function (): void {
    it('is stopped by the database even when the service check is bypassed', function (): void {
        $invoice = cancellableInvoice();
        $this->invoices->cancel($invoice, 'First cancellation', $this->owner);

        // The race, reproduced where it is actually decided. Two requests both read `status = issued` before
        // either commits, so both pass the service check — which is why that check is not the protection.
        // The loser reaches `PostingService::reverse()`, and the journal entry immutability trigger refuses to
        // reverse an entry that is already reversed.
        $secondReversal = fn () => app(PostingService::class)->reverse(
            JournalEntry::query()->findOrFail($invoice->journal_entry_id),
            'A racing second cancellation',
            CarbonImmutable::parse('2026-06-20'),
            $this->owner,
        );

        catchPlatformException($secondReversal);

        expect(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(1);
    });

    it('refuses any further change to a cancelled invoice at the database', function (): void {
        $invoice = cancellableInvoice();
        $this->invoices->cancel($invoice, 'First cancellation', $this->owner);

        // The other half: even a direct write cannot rewrite the cancellation or move the invoice on.
        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())
            ->update(['cancellation_reason' => 'Rewritten after the fact']))
            ->toThrow(QueryException::class);

        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())
            ->update(['status' => 'issued']))
            ->toThrow(QueryException::class);
    });

    it('refuses cancellation details on an invoice that is not being cancelled', function (): void {
        $invoice = cancellableInvoice();

        // The gap the frozen-column list could not close: these columns must be writable during the cancelling
        // update, so they cannot simply be frozen. The trigger guards them on every other update instead.
        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())
            ->update(['cancellation_reason' => 'Never actually cancelled']))
            ->toThrow(QueryException::class);
    });
});

describe('tenant and company isolation', function (): void {
    it('keeps the reversal in the invoice’s own company', function (): void {
        $invoice = cancellableInvoice();

        $this->invoices->cancel($invoice, 'Cancelled by customer', $this->owner);

        $reversal = JournalEntry::query()
            ->findOrFail(JournalEntry::query()->findOrFail($invoice->journal_entry_id)->reversed_by_entry_id);

        expect($reversal->company_id)->toBe($invoice->company_id)
            ->and($reversal->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('cannot be reached from another tenant', function (): void {
        $invoice = cancellableInvoice();

        $other = $this->createWorkspace('other');
        $this->withinTenant($other['tenant']);

        // Row level security scopes the lookup, so the invoice simply is not there — the cancellation cannot
        // even be attempted from the wrong workspace.
        expect(SalesInvoice::query()->whereKey($invoice->getKey())->exists())->toBeFalse();

        $this->withinTenant($this->acme['tenant']);

        expect(SalesInvoice::query()->whereKey($invoice->getKey())->exists())->toBeTrue();
    });
});
