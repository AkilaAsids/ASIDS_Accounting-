<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\ForeignAmount;
use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The foreign-currency overlay on the ledger — Stage 2 of Wave 9a (ADR 0022 §B), the ledger-wide change.
 *
 * `journal_lines` always stores `debit`/`credit` in base currency; a foreign line records its transaction
 * face in the overlay columns (`transaction_currency_code`/`transaction_amount`/`exchange_rate`), and a
 * base-currency line leaves them NULL — byte-for-byte today. This proves the overlay round-trips when a
 * `JournalLineData` carries a `ForeignAmount`, that every base-currency path leaves the overlay NULL now that
 * `journal_lines_single_currency_until_fx_phase` is dropped, and that the balance machinery is unaffected
 * (the base `debit`/`credit` remain the authority).
 *
 * RED expectation before Stage 2 lands: `ForeignAmount` and `JournalLineData.foreign` do not exist, and the
 * phase CHECK still refuses an overlay row.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->posting = app(PostingService::class);
    $this->expense = Account::query()->forCompany($this->company->getKey())->where('code', '6200')->firstOrFail();
    $this->payable = Account::query()->forCompany($this->company->getKey())->where('code', '2110')->firstOrFail();
});

describe('the foreign-currency overlay', function (): void {
    it('round-trips the overlay on a foreign line, and leaves a base line NULL', function (): void {
        // A JV standing in for a foreign posting: Dr Expense base 300,000 (overlay USD 1,000 @ 300),
        // Cr Trade Payables base 300,000 (no overlay). Balances in base.
        $entry = $this->posting->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Foreign JV',
            lines: [
                new JournalLineData(
                    accountId: (string) $this->expense->getKey(),
                    debit: Money::of('300000.00', 'LKR'),
                    foreign: new ForeignAmount(Money::of('1000.00', 'USD'), '300.0000000000'),
                ),
                new JournalLineData(
                    accountId: (string) $this->payable->getKey(),
                    credit: Money::of('300000.00', 'LKR'),
                ),
            ],
            documentType: DocumentType::JournalVoucher,
        ), $this->owner);

        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->getKey())->get()->keyBy('account_id');

        $fx = $lines[$this->expense->getKey()];
        expect($fx->transaction_currency_code)->toBe('USD')
            ->and($fx->transaction_amount)->toBe('1000.0000')
            ->and((float) $fx->exchange_rate)->toBe(300.0)
            ->and((float) $fx->debit)->toBe(300000.0);   // base debit unchanged (numeric(19,4))

        $base = $lines[$this->payable->getKey()];
        expect($base->transaction_currency_code)->toBeNull()
            ->and($base->transaction_amount)->toBeNull()
            ->and($base->exchange_rate)->toBeNull();
    });

    it('leaves the overlay NULL on every line of a base-currency entry (the base path is unchanged)', function (): void {
        $entry = $this->posting->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Base JV',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->getKey(), debit: Money::of('500.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->payable->getKey(), credit: Money::of('500.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
        ), $this->owner);

        $overlayColumns = DB::table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())
            ->get(['transaction_currency_code', 'transaction_amount', 'exchange_rate']);

        foreach ($overlayColumns as $line) {
            expect($line->transaction_currency_code)->toBeNull()
                ->and($line->transaction_amount)->toBeNull()
                ->and($line->exchange_rate)->toBeNull();
        }
    });

    it('a reversal of a base entry also writes a NULL overlay', function (): void {
        $entry = $this->posting->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Base JV to reverse',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->getKey(), debit: Money::of('500.00', 'LKR')),
                new JournalLineData(accountId: (string) $this->payable->getKey(), credit: Money::of('500.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
        ), $this->owner);

        $reversal = $this->posting->reverse($entry, 'a correction', CarbonImmutable::parse('2026-06-16'), $this->owner);

        $overlayColumns = DB::table('journal_lines')
            ->where('journal_entry_id', $reversal->getKey())
            ->get(['transaction_currency_code']);

        foreach ($overlayColumns as $line) {
            expect($line->transaction_currency_code)->toBeNull();
        }
    });

    it('still refuses an unbalanced base entry (the balance machinery is unaffected by the overlay)', function (): void {
        $exception = catchPlatformException(fn () => $this->posting->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Unbalanced',
            lines: [
                new JournalLineData(
                    accountId: (string) $this->expense->getKey(),
                    debit: Money::of('300000.00', 'LKR'),
                    foreign: new ForeignAmount(Money::of('1000.00', 'USD'), '300.0000000000'),
                ),
                new JournalLineData(accountId: (string) $this->payable->getKey(), credit: Money::of('299999.00', 'LKR')),
            ],
            documentType: DocumentType::JournalVoucher,
        ), $this->owner));

        expect($exception)->not->toBeNull();
    });
});
