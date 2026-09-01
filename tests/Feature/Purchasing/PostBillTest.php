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
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Purchasing\Application\DTOs\BillData;
use Asids\Core\Purchasing\Application\DTOs\BillLineData;
use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Exceptions\BillCannotBePosted;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Posting: the moment a draft bill becomes a payable and reaches the ledger — Stage 5 of Wave 7 (ADR 0019 §C4).
 *
 * The payable-side mirror of `IssueInvoiceTest`, with the posting map's debits and credits swapped; the
 * lifecycle is identical. The happy path is the least interesting thing here — what earns the tests is
 * everything that must *not* happen when posting fails, and the two-series numbering.
 *
 * The numbering group is the one to read first. The bill's own number (`BILL-…`, non-gapless) and its journal
 * entry's number (`JV-…`, gapless) come from *different* counters — `document_sequences` is keyed on document
 * type — and Stage 5 types the entry `JournalVoucher` precisely so the two series never gap each other. A single
 * counter would give the entry `BILL-…0002` and leave the next bill at 0003; every single-bill test passes
 * either way, so only posting several in a row exposes it (ADR §B, the two-series risk).
 *
 * RED expectation before Stage 5 lands: `BillService`, `Bill`, `BillCannotBePosted` and `DocumentType::Bill` do
 * not exist, so `app(BillService::class)` and the fixtures error every test.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->bills = app(BillService::class);

    $this->purchases = postingBillAccount('5100');    // Expense
    $this->inputVat = postingBillAccount('1170');     // Asset — Input VAT Recoverable
    $this->tradePayables = postingBillAccount('2110'); // Liability — the credit
    $this->outputVat = postingBillAccount('2140');    // Liability — the output side of a charging code

    $this->supplier = app(SupplierService::class)->create($this->company, new SupplierData(
        name: 'Silva Suppliers',
        code: 'SILVA',
    ));
});

function postingBillAccount(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * A draft built through the service, so its figures are real. A fresh supplier-invoice-number each call.
 */
function postableBillDraft(string $unitPrice = '1000.00', ?string $taxCode = null, string $date = '2026-06-15'): Bill
{
    static $counter = 0;
    $counter++;

    return app(BillService::class)->createDraft(test()->company, new BillData(
        supplierId: (string) test()->supplier->getKey(),
        billDate: CarbonImmutable::parse($date),
        supplierInvoiceNumber: 'SUP-'.$counter,
        lines: [new BillLineData(
            description: 'Office supplies',
            quantity: '1',
            unitPrice: $unitPrice,
            expenseAccountId: (string) test()->purchases->getKey(),
            taxCode: $taxCode,
        )],
    ));
}

/**
 * A VAT code charging the given rate, with both an output account (required) and an input account (set unless
 * `$withInput` is false — the day-one state that makes posting refuse, AC-3.7).
 */
function billChargingVat(string $rate = '18', bool $withInput = true): TaxCode
{
    return app(TaxCodeService::class)->create(test()->company, new TaxCodeData(
        code: 'VAT',
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        outputAccountId: (string) test()->outputVat->getKey(),
        inputAccountId: $withInput ? (string) test()->inputVat->getKey() : null,
    ));
}

describe('posting a draft', function (): void {
    it('gives it a number, a date and a ledger entry', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        expect($bill->status->value)->toBe('posted')
            ->and($bill->number)->toBe('BILL-2026-06-0001')
            ->and($bill->posted_at)->not->toBeNull()
            ->and($bill->journal_entry_id)->not->toBeNull();
    });

    it('records who posted it', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        expect((string) $bill->posted_by_id)->toBe((string) $this->owner->getKey());
    });

    it('numbers the journal entry from the journal voucher series, not the bill series', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        $entry = JournalEntry::query()->findOrFail($bill->journal_entry_id);

        // The two-series decision, asserted rather than assumed (ADR §B). A single counter would give the entry
        // BILL-2026-06-0002 and leave the next bill at 0003.
        expect($entry->number)->toBe('JV-2026-06-0001')
            ->and($entry->document_type)->toBe(DocumentType::JournalVoucher)
            ->and($entry->status)->toBe(JournalEntryStatus::Posted);
    });

    it('posts a balanced Dr Expense + Dr Input VAT = Cr Trade Payables entry', function (): void {
        billChargingVat();
        $bill = $this->bills->post(postableBillDraft(taxCode: 'VAT'), $this->owner);

        $entry = JournalEntry::query()->with('lines')->findOrFail($bill->journal_entry_id);

        $debits = $entry->lines->sum(fn ($line): float => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line): float => (float) $line->credit);

        expect($debits)->toBe($credits)
            ->and($debits)->toBe(1180.0)
            ->and($bill->total)->toBe('1180.0000');

        $byAccount = $entry->lines->keyBy('account_id');

        expect((float) $byAccount[$this->purchases->getKey()]->debit)->toBe(1000.0)
            ->and((float) $byAccount[$this->inputVat->getKey()]->debit)->toBe(180.0)
            ->and((float) $byAccount[$this->tradePayables->getKey()]->credit)->toBe(1180.0);
    });

    it('records the bill as the entry’s source document', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        $entry = JournalEntry::query()->findOrFail($bill->journal_entry_id);

        expect($entry->source_type)->toBe(Bill::MORPH_ALIAS)
            ->and($entry->source_id)->toBe((string) $bill->getKey())
            ->and($entry->reference)->toBe($bill->number);
    });

    it('does not recompute the stored amounts', function (): void {
        billChargingVat();
        $draft = postableBillDraft(taxCode: 'VAT');

        $before = [$draft->subtotal, $draft->tax_total, $draft->total];
        $lineBefore = $draft->lines->first()->only(['line_subtotal', 'tax_amount', 'tax_rate']);

        $posted = $this->bills->post($draft, $this->owner);

        // Posting resolves accounts, never money — re-resolving the rate would reprice a document already agreed.
        expect([$posted->subtotal, $posted->tax_total, $posted->total])->toBe($before)
            ->and($posted->lines()->first()->only(['line_subtotal', 'tax_amount', 'tax_rate']))->toBe($lineBefore);
    });

    it('increases the supplier’s outstanding payable by the bill total', function (): void {
        $this->bills->post(postableBillDraft('1000.00'), $this->owner);

        // The ledger effect (AC-3.9), asserted through the outstanding scope so it holds independently of which
        // stage rebinds the probe. The probe form is `PayableBalanceProbeTest`.
        $outstanding = Bill::query()
            ->forCompany($this->company->getKey())
            ->where('supplier_id', $this->supplier->getKey())
            ->outstanding()
            ->sum('amount_due');

        expect(bcadd((string) $outstanding, '0', 4))->toBe('1000.0000');
    });
});

describe('the two number series', function (): void {
    it('advances each series independently across several bills', function (): void {
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $bill = $this->bills->post(postableBillDraft(), $this->owner);
            $entry = JournalEntry::query()->findOrFail($bill->journal_entry_id);

            $numbers[] = [$bill->number, $entry->number];
        }

        // The test that would catch a shared-counter defect. Every single-bill assertion passes either way; only
        // the sequence shows it (ADR §B, and the ADR 0009 §B risk it inherits).
        expect($numbers)->toBe([
            ['BILL-2026-06-0001', 'JV-2026-06-0001'],
            ['BILL-2026-06-0002', 'JV-2026-06-0002'],
            ['BILL-2026-06-0003', 'JV-2026-06-0003'],
        ]);
    });

    it('keeps the two counters independent in the sequence table', function (): void {
        $this->bills->post(postableBillDraft(), $this->owner);
        $this->bills->post(postableBillDraft(), $this->owner);

        $sequences = DB::table('document_sequences')
            ->where('company_id', $this->company->getKey())
            ->where('period_key', '2026-06')
            ->pluck('next_number', 'document_type');

        // Two rows, each at 3 after two bills. One row at 5 would be the shared-counter bug.
        expect((int) $sequences[DocumentType::Bill->value])->toBe(3)
            ->and((int) $sequences[DocumentType::JournalVoucher->value])->toBe(3);
    });
});

describe('what posting refuses', function (): void {
    it('refuses a bill that is not a draft', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        $exception = catchPlatformException(fn () => $this->bills->post($bill->refresh(), $this->owner));

        expect($exception)->toBeInstanceOf(BillCannotBePosted::class)
            ->and($exception->problemCode())->toBe('bill-not-a-draft');
    });

    it('refuses a bill totalling zero', function (): void {
        $draft = postableBillDraft(unitPrice: '0.00');

        $exception = catchPlatformException(fn () => $this->bills->post($draft, $this->owner));

        expect($exception)->toBeInstanceOf(BillCannotBePosted::class)
            ->and($exception->problemCode())->toBe('bill-total-not-positive');
    });

    it('refuses a bill dated in a closed period, before any number is reserved', function (): void {
        $draft = postableBillDraft();

        FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->containing(CarbonImmutable::parse('2026-06-15'))
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $exception = catchPlatformException(fn () => $this->bills->post($draft, $this->owner));

        expect($exception)->toBeInstanceOf(BillCannotBePosted::class)
            ->and($exception->problemCode())->toBe('bill-period-not-open');

        // Nothing reserved: no bill number was consumed.
        expect(DB::table('document_sequences')->where('document_type', DocumentType::Bill->value)->count())->toBe(0);
    });

    it('refuses a line whose tax code has no input account, naming the code', function (): void {
        // AC-3.7 through the full post path — the first production path that can fail this way.
        billChargingVat(withInput: false);
        $draft = postableBillDraft(taxCode: 'VAT');

        $exception = catchPlatformException(fn () => $this->bills->post($draft->refresh(), $this->owner));

        expect($exception)->toBeInstanceOf(BillCannotBePosted::class)
            ->and($exception->problemCode())->toBe('tax-input-account-missing')
            ->and($exception->getMessage())->toContain('VAT');
    });

    it('refuses when the supplier has been archived since the draft was written', function (): void {
        $draft = postableBillDraft();

        app(SupplierService::class)->archive($this->supplier);

        // Posting is what creates the payable, so it is a new bill in the sense that matters — re-validated.
        $exception = catchPlatformException(fn () => $this->bills->post($draft->refresh(), $this->owner));

        expect($exception->problemCode())->toBe('supplier-not-billable');
    });

    it('refuses when the expense account has been archived since the draft was written', function (): void {
        $draft = postableBillDraft();

        DB::table('accounts')->where('id', $this->purchases->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        $exception = catchPlatformException(fn () => $this->bills->post($draft, $this->owner));

        expect($exception)->toBeInstanceOf(BillCannotBePosted::class)
            ->and($exception->problemCode())->toBe('posting-account-not-postable');
    });

    it('refuses a tax code belonging to another company', function (): void {
        billChargingVat();
        $draft = postableBillDraft(taxCode: 'VAT');

        $other = $this->createWorkspace('other');

        RowLevelSecurity::bypass(fn () => DB::table('tax_codes')->where('code', 'VAT')
            ->update(['company_id' => $other['company']->getKey(), 'tenant_id' => $other['tenant']->getKey()]));

        $exception = catchPlatformException(fn () => $this->bills->post($draft->refresh(), $this->owner));

        expect($exception->problemCode())->toBe('tax-code-outside-company');
    });
});

describe('a failed post leaves nothing behind', function (): void {
    it('consumes no bill number, posts nothing, and leaves the draft alone', function (): void {
        $draft = postableBillDraft();

        DB::table('accounts')->where('id', $this->purchases->getKey())
            ->update(['is_active' => false, 'archived_at' => now()]);

        catchPlatformException(fn () => $this->bills->post($draft, $this->owner));

        $after = DB::table('bills')->where('id', $draft->getKey())->first();

        expect($after->status)->toBe('draft')
            ->and($after->number)->toBeNull()
            ->and($after->posted_at)->toBeNull()
            ->and($after->journal_entry_id)->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0)
            ->and(DB::table('document_sequences')->count())->toBe(0);
    });
});

describe('posting the same bill twice', function (): void {
    it('is refused the second time', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        catchPlatformException(fn () => $this->bills->post($bill->refresh(), $this->owner));

        expect(JournalEntry::query()->where('source_id', (string) $bill->getKey())->count())->toBe(1);
    });

    it('is stopped by the database even when the service check is bypassed entirely', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        // The concurrency case, reproduced deterministically: a second entry citing the same source document is
        // refused by the unique index over `journal_entries.source_id` — the authority the application cannot see.
        $secondPosting = fn () => app(PostingService::class)->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'A racing second posting of the same bill',
            lines: [
                new JournalLineData(accountId: (string) $this->purchases->getKey(), debit: Money::of('1000.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->tradePayables->getKey(), credit: Money::of('1000.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
            source: SourceDocument::for($bill),
        ), $this->owner);

        expect($secondPosting)->toThrow(QueryException::class);

        expect(JournalEntry::query()->where('source_id', (string) $bill->getKey())->count())->toBe(1);
    });

    it('refuses to let a posted bill be rewound to draft', function (): void {
        $bill = $this->bills->post(postableBillDraft(), $this->owner);

        // Without this, a caller could clear the posted state and post again — consuming a second number and a
        // second entry for a bill already in the books (the immutability trigger, ADR §A5).
        expect(fn () => DB::table('bills')->where('id', $bill->getKey())->update([
            'status' => 'draft',
            'number' => null,
            'posted_at' => null,
            'journal_entry_id' => null,
        ]))->toThrow(QueryException::class);
    });
});
