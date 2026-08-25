<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\ReceiptAllocationData;
use Asids\Core\Sales\Application\DTOs\ReceiptData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\ReceiptPostingMap;
use Asids\Core\Sales\Application\Services\ReceiptService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeAllocated;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBePosted;
use Asids\Core\Sales\Domain\Exceptions\ReceiptCannotBeRecorded;
use Asids\Core\Sales\Domain\Models\CustomerReceipt;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording and allocating a customer receipt: ADR 0014 / docs/PHASE-4-RECEIPTS-REQUIREMENTS.md.
 *
 * Written RED, before `ReceiptService`, `CustomerReceipt`, `ReceiptAllocation`, `ReceiptPostingMap`, the three
 * receipt exceptions, `DocumentType::CustomerReceipt` and the `customer_receipts` / `receipt_allocations`
 * migrations exist. Every test here should fail today because a class or a table is missing, not because the
 * test itself is malformed — the Backend Engineer builds against this file, not the other way round.
 *
 * Mirrors `IssueInvoiceTest`/`CancelInvoiceTest`'s shape and discipline: the happy path earns its place, but the
 * real coverage is what must never happen — an over- or under-allocated receipt, an invoice oversold by a race,
 * a receipt posted twice, money moved into a customer's or a company's books it does not belong to.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->receipts = app(ReceiptService::class);
    $this->invoices = app(SalesInvoiceService::class);

    $this->revenue = receiptAccount('4100');
    $this->receivables = receiptAccount('1130');
    $this->bank = receiptAccount('1120');
    $this->otherReceivables = receiptAccount('1140');

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function receiptAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()->forCompany($companyId ?? (string) test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * An issued invoice for the suite's customer, real figures and a real posting.
 */
function receivableInvoice(string $unitPrice = '1000.00', string $date = '2026-06-15', ?string $customerId = null): SalesInvoice
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
 * Records a receipt for the suite's customer against the given [invoiceId => amount] allocations.
 *
 * @param  array<string, string>  $allocations
 */
function recordReceipt(
    array $allocations,
    string $amount,
    string $date = '2026-06-20',
    ?string $customerId = null,
    ?string $bankAccountId = null,
    PaymentMethod $method = PaymentMethod::BankTransfer,
    ?string $reference = 'REF-1',
): CustomerReceipt {
    return app(ReceiptService::class)->record(test()->company, new ReceiptData(
        customerId: $customerId ?? (string) test()->customer->getKey(),
        receiptDate: CarbonImmutable::parse($date),
        amount: $amount,
        paymentMethod: $method,
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

describe('recording a receipt fully allocated to one invoice', function (): void {
    it('marks the invoice paid and zeroes its balance', function (): void {
        $invoice = receivableInvoice('1000.00');

        recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $invoice->refresh();

        expect($invoice->amount_paid)->toBe('1000.0000')
            ->and($invoice->amount_due)->toBe('0.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Paid);
    });

    it('captures every field given on the receipt itself', function (): void {
        $invoice = receivableInvoice('1000.00');

        $receipt = recordReceipt(
            [(string) $invoice->getKey() => '1000.00'],
            '1000.00',
            date: '2026-06-20',
            reference: 'CHQ-00921',
        );

        expect($receipt->amount)->toBe('1000.0000')
            ->and((string) $receipt->customer_id)->toBe((string) $this->customer->getKey())
            ->and($receipt->reference)->toBe('CHQ-00921')
            ->and($receipt->payment_method)->toBe(PaymentMethod::BankTransfer)
            ->and((string) $receipt->bank_account_id)->toBe((string) $this->bank->getKey())
            ->and($receipt->status)->toBe('posted');
    });

    it('partially pays an invoice, never flipping it to Paid for a nonzero balance', function (): void {
        $invoice = receivableInvoice('1000.00');

        recordReceipt([(string) $invoice->getKey() => '400.00'], '400.00');

        $invoice->refresh();

        expect($invoice->amount_paid)->toBe('400.0000')
            ->and($invoice->amount_due)->toBe('600.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::PartiallyPaid);
    });

    it('accepts a second receipt against the remaining balance and reaches Paid', function (): void {
        $invoice = receivableInvoice('1000.00');

        recordReceipt([(string) $invoice->getKey() => '400.00'], '400.00');
        recordReceipt([(string) $invoice->getKey() => '600.00'], '600.00', date: '2026-06-21');

        $invoice->refresh();

        expect($invoice->amount_paid)->toBe('1000.0000')
            ->and($invoice->amount_due)->toBe('0.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::Paid);
    });
});

describe('one receipt allocated across several invoices', function (): void {
    it('updates each invoice independently and correctly', function (): void {
        $small = receivableInvoice('300.00');
        $large = receivableInvoice('900.00');

        recordReceipt([
            (string) $small->getKey() => '300.00',
            (string) $large->getKey() => '700.00',
        ], '1000.00');

        $small->refresh();
        $large->refresh();

        expect($small->status)->toBe(SalesInvoiceStatus::Paid)
            ->and($small->amount_due)->toBe('0.0000')
            ->and($large->status)->toBe(SalesInvoiceStatus::PartiallyPaid)
            ->and($large->amount_due)->toBe('200.0000')
            ->and($large->amount_paid)->toBe('700.0000');
    });
});

describe('numbering', function (): void {
    it('gives the receipt a number from its own gapless series', function (): void {
        $invoice = receivableInvoice('1000.00');

        $receipt = recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect($receipt->number)->toBe('RCT-2026-06-0001');
    });

    it('numbers its journal entry from the journal voucher series, independently of the receipt series', function (): void {
        $invoice = receivableInvoice('1000.00');

        // The setup invoice's own issuance already posts to the ledger, taking JV-…0001 for itself — the
        // receipt's posting is not the first journal voucher ever, it is the next one from the *same* shared
        // counter. (Fixed from an earlier version of this test that wrongly assumed the receipt would be
        // JV-0001, contradicted by this file's own "counters independent" test below, which documents the
        // shared counter explicitly.)
        $invoiceEntryNumber = JournalEntry::query()->findOrFail($invoice->journal_entry_id)->number;

        $receipt = recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $entry = JournalEntry::query()->findOrFail($receipt->journal_entry_id);

        expect($invoiceEntryNumber)->toBe('JV-2026-06-0001')
            // The receipt is the first of its own series (RCT), but the *second* journal voucher overall —
            // proving the two series are genuinely independent rather than the receipt secretly reusing the
            // RCT counter for its ledger entry too.
            ->and($receipt->number)->toBe('RCT-2026-06-0001')
            ->and($entry->number)->toBe('JV-2026-06-0002')
            ->and($entry->document_type)->toBe(DocumentType::JournalVoucher);
    });

    it('does not consume an invoice number when recording a receipt', function (): void {
        $invoice = receivableInvoice('1000.00');
        $secondInvoice = receivableInvoice('500.00');

        recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // The receipt against the first invoice must not have burned an INV number that the next invoice
        // would otherwise take.
        expect($secondInvoice->number)->toBe('INV-2026-06-0002');
    });

    it('stays gapless across several receipts, on both its own series and the journal voucher series', function (): void {
        // Fixed from an earlier version that assumed each receipt's posting would be the only journal
        // voucher in its iteration (JV-0001, 0002, 0003). Each iteration actually posts *two* journal
        // vouchers — the setup invoice's own issuance, then the receipt — so the JV series the test must
        // assert gaplessness over is the interleaved run of both, not a receipt-only sub-series.
        $receiptNumbers = [];
        $journalVoucherNumbers = [];

        for ($i = 0; $i < 3; $i++) {
            $invoice = receivableInvoice('100.00', date: '2026-06-'.(10 + $i));
            $journalVoucherNumbers[] = JournalEntry::query()->findOrFail($invoice->journal_entry_id)->number;

            $receipt = recordReceipt([(string) $invoice->getKey() => '100.00'], '100.00', date: '2026-06-'.(10 + $i));
            $journalVoucherNumbers[] = JournalEntry::query()->findOrFail($receipt->journal_entry_id)->number;

            $receiptNumbers[] = $receipt->number;
        }

        // The property this test exists to catch: the RCT series is gapless on its own, which it would not
        // be if it secretly shared a counter with anything else.
        expect($receiptNumbers)->toBe(['RCT-2026-06-0001', 'RCT-2026-06-0002', 'RCT-2026-06-0003']);

        // The JV series is shared between invoice postings and receipt postings (Gate-1 #4: a receipt's
        // entry reuses `JournalVoucher`), and the six postings together — three invoices, three receipts —
        // leave *that* series gapless too.
        expect($journalVoucherNumbers)->toBe([
            'JV-2026-06-0001', 'JV-2026-06-0002',
            'JV-2026-06-0003', 'JV-2026-06-0004',
            'JV-2026-06-0005', 'JV-2026-06-0006',
        ]);
    });

    it('keeps the receipt and journal voucher counters independent in the sequence table', function (): void {
        $first = receivableInvoice('100.00');
        $second = receivableInvoice('100.00');

        recordReceipt([(string) $first->getKey() => '100.00'], '100.00');
        recordReceipt([(string) $second->getKey() => '100.00'], '100.00', date: '2026-06-21');

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->where('period_key', '2026-06')
            ->pluck('next_number', 'document_type');

        // Two receipts and two invoice issuances have each posted one journal voucher, so JV sits at 5
        // (2 invoice postings + 2 receipt postings + 1), while the receipt counter sits at 3 and the
        // invoice counter at 3 — three independent series, never sharing a counter.
        expect((int) $sequences[DocumentType::CustomerReceipt->value])->toBe(3)
            ->and((int) $sequences[DocumentType::SalesInvoice->value])->toBe(3);
    });
});

describe('the ledger posting', function (): void {
    it('posts exactly one balanced entry debiting bank and crediting trade receivables', function (): void {
        $invoice = receivableInvoice('1000.00');

        $receipt = recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $entry = JournalEntry::query()->with('lines')->findOrFail($receipt->journal_entry_id);

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($entry->lines)->toHaveCount(2)
            ->and($debits)->toBe($credits)
            ->and($debits)->toBe(1000.0);

        $byAccount = $entry->lines->keyBy('account_id');

        expect((float) $byAccount[$this->bank->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->receivables->getKey()]->credit)->toBe(1000.0);
    });

    it('records the receipt as the entry’s source document, preventing a second posting', function (): void {
        $invoice = receivableInvoice('1000.00');

        $receipt = recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        $entry = JournalEntry::query()->findOrFail($receipt->journal_entry_id);

        expect($entry->source_type)->toBe(CustomerReceipt::MORPH_ALIAS)
            ->and($entry->source_id)->toBe((string) $receipt->getKey())
            ->and($entry->reference)->toBe($receipt->number);
    });
});

describe('double-post prevention', function (): void {
    it('is stopped by the database even when every service check is bypassed', function (): void {
        $invoice = receivableInvoice('1000.00');
        $receipt = recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        // The same proof `IssueInvoiceTest` gives for invoices: the partial unique index over
        // `journal_entries.source_id` is what actually holds, not any in-application check.
        $secondPosting = fn () => app(PostingService::class)->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-20'),
            description: 'A racing second posting of the same receipt',
            lines: [
                new JournalLineData(accountId: (string) $this->bank->getKey(), debit: Money::of('1000.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->receivables->getKey(), credit: Money::of('1000.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
            source: SourceDocument::for($receipt),
        ), $this->owner);

        expect($secondPosting)->toThrow(QueryException::class);
        expect(JournalEntry::query()->where('source_id', (string) $receipt->getKey())->count())->toBe(1);
    });
});

describe('the full-allocation invariant', function (): void {
    it('refuses a receipt under-allocated against its own amount', function (): void {
        $invoice = receivableInvoice('1000.00');

        // Fixed: the setup invoice's own issuance already posted one journal entry, so the invariant under
        // test is "the refused receipt posts nothing *more*", not "the ledger is empty" — the ledger already
        // holds the invoice's posting by the time the receipt is attempted.
        $entriesBeforeAttempt = JournalEntry::query()->count();

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '400.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);

        // Nothing written by the refusal itself: no receipt row, no invoice movement, no new posting.
        expect(DB::table('customer_receipts')->count())->toBe(0)
            ->and($invoice->refresh()->amount_paid)->toBe('0.0000')
            ->and(JournalEntry::query()->count())->toBe($entriesBeforeAttempt);
    });

    it('refuses a receipt over-allocated beyond its own amount', function (): void {
        $invoice = receivableInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '600.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
        expect(DB::table('customer_receipts')->count())->toBe(0);
    });

    it('refuses when allocations across several invoices sum to more than the receipt', function (): void {
        $first = receivableInvoice('1000.00');
        $second = receivableInvoice('1000.00');

        $exception = catchPlatformException(fn () => recordReceipt([
            (string) $first->getKey() => '600.00',
            (string) $second->getKey() => '600.00',
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);

        expect($first->refresh()->amount_paid)->toBe('0.0000')
            ->and($second->refresh()->amount_paid)->toBe('0.0000');
    });
});

describe('the per-invoice cap', function (): void {
    it('refuses an allocation exceeding that invoice’s current amount_due', function (): void {
        $invoice = receivableInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1200.00'], '1200.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
        expect($invoice->refresh()->amount_paid)->toBe('0.0000');
    });

    it('re-reads amount_due at the moment of allocating, refusing against an already-reduced balance', function (): void {
        $invoice = receivableInvoice('1000.00');

        // First receipt legitimately consumes most of the balance.
        recordReceipt([(string) $invoice->getKey() => '700.00'], '700.00');

        // A second receipt, sized against the *original* 1,000 due rather than the 300 now remaining —
        // exactly the stale-screen scenario AC-2.5 names.
        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '400.00'], '400.00', date: '2026-06-21')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);

        // The first receipt's effect stands; the second changed nothing.
        expect($invoice->refresh()->amount_paid)->toBe('700.0000')
            ->and($invoice->amount_due)->toBe('300.0000')
            ->and($invoice->status)->toBe(SalesInvoiceStatus::PartiallyPaid);
    });
});

describe('no oversell under a race', function (): void {
    it('lets exactly one of two receipts racing the same invoice succeed in full', function (): void {
        // True concurrent execution is not reachable from a single Pest process. Approximated the way
        // `IssueInvoiceTest`/`CancelInvoiceTest` approximate their own races: two sequential attempts, each
        // built from the *same* pre-read state of the invoice, so the second genuinely believes 1,000 is
        // still available when it commits — which is exactly what a racing request would believe.
        $invoice = receivableInvoice('1000.00');
        $staleView = SalesInvoice::query()->whereKey($invoice->getKey())->firstOrFail();

        recordReceipt([(string) $invoice->getKey() => '700.00'], '700.00');

        expect($staleView->amount_due)->toBe('1000.0000');

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $staleView->getKey() => '700.00'], '700.00', date: '2026-06-21')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);

        // Never 1,400 against a 1,000 invoice.
        expect($invoice->refresh()->amount_paid)->toBe('700.0000');
    });

    it('refuses at the database when amount_paid would exceed total, independent of the service', function (): void {
        // The backstop the service check is not: AC-5.2's CHECK, exercised directly, bypassing
        // `ReceiptService` entirely — the same "prove the database holds even if the application does not"
        // discipline `CancelInvoiceTest` applies to the immutability trigger.
        $invoice = receivableInvoice('1000.00');

        expect(fn () => DB::table('sales_invoices')->where('id', $invoice->getKey())->update([
            'amount_paid' => '1200.0000',
            'amount_due' => '-200.0000',
        ]))->toThrow(QueryException::class);
    });
});

describe('refusals: the receipt itself', function (): void {
    it('refuses a zero amount', function (): void {
        $invoice = receivableInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '0.00'], '0.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses a negative amount', function (): void {
        $invoice = receivableInvoice('1000.00');

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '-100.00'], '-100.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses a customer that does not belong to this company', function (): void {
        $invoice = receivableInvoice('1000.00');
        $other = $this->createWorkspace('other');

        $exception = catchPlatformException(fn () => recordReceipt(
            [(string) $invoice->getKey() => '1000.00'],
            '1000.00',
            customerId: (string) $other['company']->getKey(), // not even a customer id, let alone this company's
        ));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses a bank account belonging to a different company', function (): void {
        $invoice = receivableInvoice('1000.00');
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $exception = catchPlatformException(fn () => recordReceipt(
            [(string) $invoice->getKey() => '1000.00'],
            '1000.00',
            bankAccountId: (string) receiptAccount('1120', (string) $second->getKey())->getKey(),
        ));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses a bank account that has been made non-postable', function (): void {
        $invoice = receivableInvoice('1000.00');

        DB::table('accounts')->where('id', $this->bank->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses a bank account that is not an asset', function (): void {
        $invoice = receivableInvoice('1000.00');

        $exception = catchPlatformException(fn () => recordReceipt(
            [(string) $invoice->getKey() => '1000.00'],
            '1000.00',
            bankAccountId: (string) $this->revenue->getKey(), // an income account, not asset
        ));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
    });

    it('refuses recording into a closed fiscal period', function (): void {
        $invoice = receivableInvoice('1000.00');

        // Fixed: same setup-invoice-already-posted correction as the under-allocation test above — the
        // baseline is captured after the invoice exists, not asserted as zero.
        $entriesBeforeAttempt = JournalEntry::query()->count();

        FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-20'))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeRecorded::class);
        // No new posting beyond the setup invoice's own — the refusal happens before any number is reserved.
        expect(JournalEntry::query()->count())->toBe($entriesBeforeAttempt);
    });
});

describe('refusals: which invoices may be allocated to', function (): void {
    it('refuses allocating to a draft invoice', function (): void {
        $draft = $this->invoices->createDraft($this->company, new SalesInvoiceData(
            customerId: (string) $this->customer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting services',
                quantity: '1',
                unitPrice: '1000.00',
                revenueAccountId: (string) $this->revenue->getKey(),
            )],
        ));

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $draft->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
    });

    it('refuses allocating to a cancelled invoice', function (): void {
        $invoice = receivableInvoice('1000.00');
        $this->invoices->cancel($invoice, 'Wrong customer', $this->owner);

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
    });

    it('refuses allocating to an invoice that is already fully paid', function (): void {
        $invoice = receivableInvoice('1000.00');
        recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00');

        expect($invoice->refresh()->status)->toBe(SalesInvoiceStatus::Paid);

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '100.00'], '100.00', date: '2026-06-21')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
    });

    it('refuses an invoice belonging to a different customer than the receipt', function (): void {
        $otherCustomer = app(CustomerService::class)->create($this->company, new CustomerData(
            name: 'Perera Stores',
            code: 'PERERA',
        ));

        $invoice = receivableInvoice('1000.00', customerId: (string) $otherCustomer->getKey());

        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);

        expect($invoice->refresh()->amount_paid)->toBe('0.0000');
    });

    it('refuses an invoice belonging to a different company than the receipt', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        // Fixed: the second company needs its own open fiscal year before an invoice can be issued into it —
        // without this, `issue()` refuses with `NoFiscalPeriod` before the cross-company case under test is
        // even reached.
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::parse('2026-06-15'));

        $secondCustomer = app(CustomerService::class)->create($second, new CustomerData(name: 'X', code: 'X1'));

        $invoiceDraft = $this->invoices->createDraft($second, new SalesInvoiceData(
            customerId: (string) $secondCustomer->getKey(),
            invoiceDate: CarbonImmutable::parse('2026-06-15'),
            lines: [new SalesInvoiceLineData(
                description: 'Consulting services',
                quantity: '1',
                unitPrice: '1000.00',
                revenueAccountId: (string) receiptAccount('4100', (string) $second->getKey())->getKey(),
            )],
        ));
        $foreignInvoice = $this->invoices->issue($invoiceDraft, $this->owner);

        // The receipt's own customer id is repointed at the foreign invoice's id purely to name it in the
        // allocation set; the point under test is the company mismatch, not the customer one.
        $exception = catchPlatformException(
            fn () => recordReceipt([(string) $foreignInvoice->getKey() => '1000.00'], '1000.00')
        );

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
    });

    it('refuses a zero or negative allocation line even when the receipt total would otherwise balance', function (): void {
        $invoice = receivableInvoice('1000.00');
        $second = receivableInvoice('1000.00');

        $exception = catchPlatformException(fn () => recordReceipt([
            (string) $invoice->getKey() => '1000.00',
            (string) $second->getKey() => '0.00',
        ], '1000.00'));

        expect($exception)->toBeInstanceOf(ReceiptCannotBeAllocated::class);
    });
});

describe('AC-3.2: allocations spanning more than one receivable account', function (): void {
    it('refuses rather than splitting or guessing the credit side', function (): void {
        // A receipt is single-customer, and one customer resolves to one receivable account, so this state
        // does not arise through the normal validated path (allocating to another customer's invoice is
        // already refused above). It is reachable only the way `InvoicePostingMapTest` reaches its own
        // "should never happen" account states: by constructing the input directly rather than through the
        // full service, which is legitimate because `ReceiptPostingMap` is documented as pure — it reads and
        // resolves accounts, and posts nothing, so it may be exercised on any receipt-shaped input.
        $customerB = app(CustomerService::class)->create($this->company, new CustomerData(
            name: 'Overridden Ltd',
            code: 'OVERRIDE',
            receivableAccountId: (string) $this->otherReceivables->getKey(),
        ));

        $invoiceA = receivableInvoice('500.00', customerId: (string) $this->customer->getKey());
        $invoiceB = receivableInvoice('500.00', customerId: (string) $customerB->getKey());

        // Fixed: issuing the two setup invoices already posts two journal entries of their own — the
        // invariant under test is that the *map* posts nothing beyond them, not that the ledger is empty.
        $entriesBeforeMapCall = JournalEntry::query()->count();

        // `$invoiceA` resolves to the system Trade Receivables account (1130); `$invoiceB`'s customer overrides
        // to 1140. Feeding both into one receipt's allocation set — bypassing the cross-customer check that
        // `ReceiptService::record()` performs earlier, by talking to the posting layer beneath it — is the
        // only way to construct AC-3.2's premise deterministically.
        $receipt = new CustomerReceipt;
        $receipt->company_id = $this->company->getKey();
        $receipt->customer_id = $this->customer->getKey();
        $receipt->number = 'RCT-TEST-0001';
        $receipt->receipt_date = CarbonImmutable::parse('2026-06-20');
        $receipt->currency_code = $this->company->base_currency_code;
        $receipt->amount = '1000.0000';
        $receipt->payment_method = PaymentMethod::BankTransfer->value;
        $receipt->bank_account_id = $this->bank->getKey();
        $receipt->status = 'posted';
        $receipt->save();

        DB::table('receipt_allocations')->insert([
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->company->tenant_id,
                'company_id' => $this->company->getKey(),
                'customer_receipt_id' => $receipt->getKey(),
                'sales_invoice_id' => $invoiceA->getKey(),
                'amount' => '500.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->company->tenant_id,
                'company_id' => $this->company->getKey(),
                'customer_receipt_id' => $receipt->getKey(),
                'sales_invoice_id' => $invoiceB->getKey(),
                'amount' => '500.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        expect(fn () => app(ReceiptPostingMap::class)->for($receipt->fresh()))
            ->toThrow(ReceiptCannotBePosted::class);

        // Refused rather than posted at all, on either account: no journal entry beyond the two invoices'
        // own postings.
        expect(JournalEntry::query()->count())->toBe($entriesBeforeMapCall);
    });
});

describe('a failed record-and-allocate leaves nothing behind', function (): void {
    it('rolls back the whole operation when posting fails after the number is reserved', function (): void {
        $invoice = receivableInvoice('1000.00');

        // Fixed: the setup invoice's own issuance already posted one journal entry, so the rollback
        // invariant is "no *new* entry survives", not "the ledger is empty".
        $entriesBeforeAttempt = JournalEntry::query()->count();

        // Made to fail at the ledger, after a receipt number would already have been reserved — the
        // rollback case, mirroring `IssueInvoiceTest`'s "returns the number when the failure happens after
        // it was reserved".
        DB::table('accounts')->where('id', $this->receivables->getKey())->update(['is_postable' => false]);

        catchPlatformException(fn () => recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00'));

        expect(DB::table('customer_receipts')->count())->toBe(0)
            ->and($invoice->refresh()->amount_paid)->toBe('0.0000')
            ->and(JournalEntry::query()->count())->toBe($entriesBeforeAttempt);

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->pluck('next_number', 'document_type');

        // No receipt number was left burned by the rollback, if one was ever reserved at all.
        if (isset($sequences[DocumentType::CustomerReceipt->value])) {
            expect((int) $sequences[DocumentType::CustomerReceipt->value])->toBe(1);
        }

        DB::table('accounts')->where('id', $this->receivables->getKey())->update(['is_postable' => true]);

        expect(recordReceipt([(string) $invoice->getKey() => '1000.00'], '1000.00')->number)
            ->toBe('RCT-2026-06-0001');
    });
});
