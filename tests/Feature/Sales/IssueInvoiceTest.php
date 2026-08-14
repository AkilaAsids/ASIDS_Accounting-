<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBeIssued;
use Asids\Core\Sales\Domain\Exceptions\InvoiceCannotBePosted;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Issuing: the moment a draft becomes a statutory document and reaches the ledger.
 *
 * Stage 3 of Milestone 5. The happy path is the least interesting thing here. What earns the tests is everything
 * that must *not* happen when issuing fails, because every one of those failures leaves evidence a customer or an
 * auditor would later have to explain:
 *
 *   * a consumed invoice number is a gap in a series a tax authority audits for completeness;
 *   * a posted entry without an invoice is money in the ledger nobody can trace;
 *   * an invoice marked issued without an entry is revenue that never reached the books.
 *
 * The numbering group is the one to read first. Invoice numbers and journal-entry numbers come from *different*
 * counters — `document_sequences` is keyed on document type — and Stage 3 chose `JournalVoucher` for the entry
 * precisely so both series stay gapless. Number the entry `SalesInvoice` instead and the invoice series runs
 * 1, 3, 5 while every individual test still passes. Only issuing several invoices in a row exposes it, which is
 * why that test exists and why it asserts both series at once.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->invoices = app(SalesInvoiceService::class);

    $this->revenue = issuingAccount('4100');
    $this->receivables = issuingAccount('1130');
    $this->outputVat = issuingAccount('2140');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function issuingAccount(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * A draft built through the service, so its figures are real rather than planted.
 */
function issuableDraft(string $unitPrice = '1000.00', ?string $taxCode = null, string $date = '2026-06-15'): SalesInvoice
{
    return app(SalesInvoiceService::class)->createDraft(test()->company, new SalesInvoiceData(
        customerId: (string) test()->customer->getKey(),
        invoiceDate: CarbonImmutable::parse($date),
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) test()->revenue->getKey(),
            taxCode: $taxCode,
        )],
    ));
}

function chargingVat(string $rate = '18'): TaxCode
{
    return TaxCode::factory()
        ->charging($rate, (string) test()->outputVat->getKey())
        ->create(['company_id' => test()->company->getKey(), 'code' => 'VAT']);
}

describe('issuing a draft', function (): void {
    it('gives it a number, a date and a ledger entry', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        expect($invoice->status)->toBe(SalesInvoiceStatus::Issued)
            ->and($invoice->number)->toBe('INV-2026-06-0001')
            ->and($invoice->issued_at)->not->toBeNull()
            ->and($invoice->journal_entry_id)->not->toBeNull();
    });

    it('numbers the journal entry from the journal voucher series, not the invoice series', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

        // The Stage 3 decision, asserted rather than assumed. A single counter feeding both would give the
        // entry INV-2026-06-0002 and leave the next invoice at 0003.
        expect($entry->number)->toBe('JV-2026-06-0001')
            ->and($entry->document_type)->toBe(DocumentType::JournalVoucher)
            ->and($entry->status)->toBe(JournalEntryStatus::Posted);
    });

    it('posts a balanced entry that ties to the invoice', function (): void {
        $vat = chargingVat();
        $invoice = $this->invoices->issue(issuableDraft(taxCode: $vat->code), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($invoice->journal_entry_id);

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($debits)->toBe($credits)
            // 1,000 at 18% — receivable takes the gross, revenue and output tax the parts.
            ->and($debits)->toBe(1180.0)
            ->and($invoice->total)->toBe('1180.0000');

        $byAccount = $entry->lines->keyBy('account_id');

        expect((float) $byAccount[$this->receivables->getKey()]->debit)->toBe(1180.0)
            ->and((float) $byAccount[$this->revenue->getKey()]->credit)->toBe(1000.0)
            ->and((float) $byAccount[$this->outputVat->getKey()]->credit)->toBe(180.0);
    });

    it('records the invoice as the entry’s source document', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

        // Traceability lives here rather than in `document_type`, which is the trade Stage 3 made to keep both
        // number series gapless.
        expect($entry->source_type)->toBe(SalesInvoice::MORPH_ALIAS)
            ->and($entry->source_id)->toBe((string) $invoice->getKey())
            ->and($entry->reference)->toBe($invoice->number);
    });

    it('does not recompute the stored amounts', function (): void {
        $vat = chargingVat();
        $draft = issuableDraft(taxCode: $vat->code);

        $before = [$draft->subtotal, $draft->tax_total, $draft->total];
        $lineBefore = $draft->lines->first()->only(['line_subtotal', 'tax_amount', 'tax_rate']);

        $issued = $this->invoices->issue($draft, $this->owner);

        // Approved decision B1: issuing resolves accounts, never money. Re-resolving the rate here would
        // silently reprice a document the customer has already agreed.
        expect([$issued->subtotal, $issued->tax_total, $issued->total])->toBe($before)
            ->and($issued->lines()->first()->only(['line_subtotal', 'tax_amount', 'tax_rate']))->toBe($lineBefore);
    });
});

describe('the two number series', function (): void {
    it('stays gapless across several invoices, on both sides', function (): void {
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $invoice = $this->invoices->issue(issuableDraft(), $this->owner);
            $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);

            $numbers[] = [$invoice->number, $entry->number];
        }

        // The test that would have caught the shared-counter defect. Every single-invoice assertion above
        // passes either way; only the sequence shows it.
        expect($numbers)->toBe([
            ['INV-2026-06-0001', 'JV-2026-06-0001'],
            ['INV-2026-06-0002', 'JV-2026-06-0002'],
            ['INV-2026-06-0003', 'JV-2026-06-0003'],
        ]);
    });

    it('keeps the two counters independent in the sequence table', function (): void {
        $this->invoices->issue(issuableDraft(), $this->owner);
        $this->invoices->issue(issuableDraft(), $this->owner);

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->where('period_key', '2026-06')
            ->pluck('next_number', 'document_type');

        // Two rows, each at 3 after two invoices. One row at 5 would be the shared-counter bug.
        expect((int) $sequences[DocumentType::SalesInvoice->value])->toBe(3)
            ->and((int) $sequences[DocumentType::JournalVoucher->value])->toBe(3);
    });

    it('leaves room for Stage 4 reversals to avoid the invoice series', function (): void {
        $this->invoices->issue(issuableDraft(), $this->owner);

        // Not a cancellation test — Stage 4 owns that. This asserts the property cancellation will depend on:
        // the entry is a journal voucher, so `PostingService::reverse()`, which copies the original's document
        // type, will draw its mirror from the JV counter and never touch the invoice series.
        $entry = JournalEntry::query()->where('source_type', SalesInvoice::MORPH_ALIAS)->firstOrFail();

        expect($entry->document_type)->toBe(DocumentType::JournalVoucher);
    });
});

describe('what issuing refuses', function (): void {
    it('refuses an invoice that is not a draft', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        $exception = catchPlatformException(fn () => $this->invoices->issue($invoice, $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBeIssued::class)
            ->and($exception->problemCode())->toBe('invoice-not-a-draft');
    });

    it('refuses an invoice with no lines', function (): void {
        $draft = issuableDraft();

        // The service will not write a draft without lines, so the only way here is the lines going underneath
        // it — which is exactly the state worth refusing at issue time.
        DB::table('sales_invoice_lines')->where('sales_invoice_id', $draft->getKey())->delete();

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft->refresh(), $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBeIssued::class)
            ->and($exception->problemCode())->toBe('invoice-has-no-lines-to-issue');
    });

    it('refuses an invoice totalling zero', function (): void {
        $draft = issuableDraft(unitPrice: '0.00');

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        // Approved decision B4: a draft may be zero, issuing one may not.
        expect($exception)->toBeInstanceOf(InvoiceCannotBeIssued::class)
            ->and($exception->problemCode())->toBe('invoice-total-not-positive');
    });

    it('refuses an invoice dated in a closed period', function (): void {
        $draft = issuableDraft();

        // `closed_at` alongside the status, because `fiscal_periods_closed_check` asserts
        // `(status = 'open') = (closed_at IS NULL)` — the database will not hold a period that claims to be
        // closed with no record of when.
        FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-15'))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBeIssued::class)
            ->and($exception->problemCode())->toBe('invoice-period-not-open');
    });

    it('refuses when the customer has been archived since the draft was written', function (): void {
        $draft = issuableDraft();

        app(CustomerService::class)->archive($this->customer);

        // Issuing is what creates the receivable, so it is a new invoice in the sense that matters.
        $exception = catchPlatformException(fn () => $this->invoices->issue($draft->refresh(), $this->owner));

        expect($exception->problemCode())->toBe('customer-not-invoiceable');
    });

    it('refuses when the revenue account has been archived since the draft was written', function (): void {
        $draft = issuableDraft();

        DB::table('accounts')->where('id', $this->revenue->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBePosted::class)
            ->and($exception->problemCode())->toBe('posting-account-not-postable');
    });

    it('refuses when the revenue account has been reclassified since the draft was written', function (): void {
        $draft = issuableDraft();

        DB::table('accounts')->where('id', $this->revenue->getKey())
            ->update(['type' => 'expense', 'normal_balance' => 'debit']);

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBePosted::class)
            ->and($exception->problemCode())->toBe('revenue-account-wrong-type');
    });

    it('refuses when a charging tax code has lost its output account', function (): void {
        $vat = chargingVat();
        $draft = issuableDraft(taxCode: $vat->code);

        // Zeroed and cleared together, because `tax_codes_output_account_required_check` refuses a charging
        // code with no output account — the database will not let this configuration exist half-made. The
        // line keeps the 180 it was charged, so the invoice still owes tax that now has nowhere to post,
        // which is precisely the drift the map guards against.
        DB::table('tax_codes')->where('id', $vat->getKey())
            ->update(['rate' => '0.0000', 'output_account_id' => null]);

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft->refresh(), $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBePosted::class)
            ->and($exception->problemCode())->toBe('tax-output-account-missing');
    });

    it('refuses when the receivable account is gone', function (): void {
        $draft = issuableDraft();

        // No customer-specific account and no system account: the company's chart is unprovisioned as far as
        // receivables are concerned.
        DB::table('accounts')->where('id', $this->receivables->getKey())
            ->update(['system_key' => null, 'is_system' => false]);

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        expect($exception)->toBeInstanceOf(InvoiceCannotBePosted::class)
            ->and($exception->problemCode())->toBe('receivable-account-missing');
    });

    it('refuses a tax code belonging to another company', function (): void {
        $vat = chargingVat();
        $draft = issuableDraft(taxCode: $vat->code);

        $other = $this->createWorkspace('other');

        // Under bypass because the write crosses tenants, and the policy refuses that — correctly. The state
        // being reproduced is a code that has been moved, not a write the application would ever make.
        RowLevelSecurity::bypass(fn () => DB::table('tax_codes')->where('id', $vat->getKey())
            ->update(['company_id' => $other['company']->getKey(), 'tenant_id' => $other['tenant']->getKey()]));

        $exception = catchPlatformException(fn () => $this->invoices->issue($draft->refresh(), $this->owner));

        expect($exception->problemCode())->toBe('tax-code-outside-company');
    });
});

describe('a failed issue leaves nothing behind', function (): void {
    /**
     * The guarantee that matters most, asserted against the database rather than the model.
     *
     * Each refusal below happens at a different point — before the transaction, inside it, after the number is
     * reserved — and the invariant is the same for all of them: the draft is untouched, the counters have not
     * moved, and the ledger is empty.
     */
    it('consumes no invoice number, posts nothing, and leaves the draft alone', function (): void {
        $draft = issuableDraft();

        DB::table('accounts')->where('id', $this->revenue->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        catchPlatformException(fn () => $this->invoices->issue($draft, $this->owner));

        $after = DB::table('sales_invoices')->where('id', $draft->getKey())->first();

        expect($after->status)->toBe('draft')
            ->and($after->number)->toBeNull()
            ->and($after->issued_at)->toBeNull()
            ->and($after->journal_entry_id)->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0)
            // No sequence row at all: nothing ever asked for a number.
            ->and(DB::table('document_sequences')->count())->toBe(0);
    });

    it('returns the number when the failure happens after it was reserved', function (): void {
        // A first invoice issues cleanly, so the counter is genuinely at 2.
        $this->invoices->issue(issuableDraft(), $this->owner);

        $doomed = issuableDraft();

        // Made to fail at the ledger rather than before it. `JournalService::assertPostable()` refuses a line
        // against a non-postable account, and that check runs inside `postNew()` — after `issue()` has already
        // taken its invoice number. Which is the case worth testing: the rollback, not the refusal.
        DB::table('accounts')->where('id', $this->receivables->getKey())->update(['is_postable' => false]);

        catchPlatformException(fn () => $this->invoices->issue($doomed, $this->owner));

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        // Still 2 — the first invoice's, and nothing more. A rollback returns the number.
        expect((int) $sequences[DocumentType::SalesInvoice->value])->toBe(2)
            ->and(DB::table('sales_invoices')->where('id', $doomed->getKey())->value('status'))->toBe('draft');

        // And the next invoice takes 0002, proving no gap was left.
        DB::table('accounts')->where('id', $this->receivables->getKey())->update(['is_postable' => true]);

        expect($this->invoices->issue(issuableDraft(), $this->owner)->number)->toBe('INV-2026-06-0002');
    });
});

describe('issuing the same invoice twice', function (): void {
    it('is refused the second time', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        catchPlatformException(fn () => $this->invoices->issue($invoice->refresh(), $this->owner));

        expect(JournalEntry::query()->where('source_id', (string) $invoice->getKey())->count())->toBe(1);
    });

    it('is stopped by the database even when the service check is bypassed entirely', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        // The concurrency case, reproduced deterministically.
        //
        // Two racing requests both read `status = draft` before either commits, so both pass `issue()`'s own
        // check — which is exactly why that check is not the protection. Rewinding the invoice to draft to
        // simulate the loser is impossible, and for a good reason: the Stage 1 immutability trigger refuses to
        // let an issued invoice return to draft. So the race is reproduced one layer down, where it actually
        // gets decided — a second entry citing the same source document.
        //
        // The unique index over `journal_entries.source_id` is what holds under concurrency, because it is
        // evaluated by the database at commit rather than by the application at read time.
        $secondPosting = fn () => app(PostingService::class)->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'A racing second posting of the same invoice',
            lines: [
                new JournalLineData(accountId: (string) $this->receivables->getKey(), debit: Money::of('1000.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->revenue->getKey(), credit: Money::of('1000.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
            source: SourceDocument::for($invoice),
        ), $this->owner);

        expect($secondPosting)->toThrow(QueryException::class);

        // One entry for this invoice, and one only.
        expect(JournalEntry::query()->where('source_id', (string) $invoice->getKey())->count())->toBe(1);
    });

    it('refuses to let an issued invoice be rewound to draft', function (): void {
        $invoice = $this->invoices->issue(issuableDraft(), $this->owner);

        // The other half of the same protection, and the reason the test above reaches for the ledger instead.
        // Without this, a caller could clear the issued state and issue again — consuming a second number for
        // a document the customer already holds.
        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'status' => 'draft',
            'number' => null,
            'issued_at' => null,
            'journal_entry_id' => null,
        ]))->toThrow(QueryException::class);
    });
});
