<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Application\Services\OpeningBalanceService;
use Asids\Core\Accounting\Application\Services\PeriodCloseService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\DocumentType;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances, closing periods, and closing a year.
 *
 * Two operations with opposite risk profiles. Opening balances are entered once and are wrong
 * forever if nobody notices — a second opening entry would double a company's starting position, and
 * because both entries balance, nothing would report a problem. Closing is reversible by design,
 * because a period that could never be reopened makes an error found in month three permanent.
 *
 * The year-end close is the only routine in the module that computes an amount rather than recording
 * one, so it gets the most attention here: that it zeroes what it should, leaves alone what it
 * should, and produces a retained earnings figure that matches the year's actual result.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->posting = app(PostingService::class);
    $this->close = app(PeriodCloseService::class);
    $this->opening = app(OpeningBalanceService::class);
    $this->balances = app(LedgerBalanceService::class);
    $this->calendar = app(FiscalCalendarService::class);

    $this->cash = acct('1110');
    $this->payables = acct('2110');
    $this->sales = acct('4100');
    $this->rent = acct('6200');
});

function acct(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

function postEntry(string $debitId, string $creditId, string $amount, string $date): JournalEntry
{
    return test()->posting->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse($date),
        description: 'Entry',
        lines: [
            new JournalLineData(accountId: $debitId, debit: Money::of($amount, 'LKR')),
            new JournalLineData(accountId: $creditId, credit: Money::of($amount, 'LKR')),
        ],
    ), test()->owner);
}

function periodOn(string $date): FiscalPeriod
{
    return test()->calendar->periodFor(test()->company, CarbonImmutable::parse($date));
}

/**
 * Closes every period up to and including the one containing the date, and returns that one.
 *
 * Periods close in order by design, so a test that wants June closed has to close January through
 * May first. Wrapping it keeps that rule visible in one place rather than repeated — and the tests
 * that assert the ordering rule itself deliberately do not use this.
 */
function closeThrough(string $date): FiscalPeriod
{
    $target = periodOn($date);

    $periods = FiscalPeriod::query()
        ->forCompany(test()->company->getKey())
        ->where('starts_on', '<=', $target->starts_on->toDateString())
        ->orderBy('starts_on')
        ->get();

    foreach ($periods as $period) {
        test()->close->close($period, test()->owner);
    }

    return $target->fresh() ?? $target;
}

describe('opening balances', function (): void {
    it('records a balanced opening position', function (): void {
        $entry = $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
            $this->payables->getKey() => '120000.00',
        ], $this->owner);

        expect($entry->document_type)->toBe(DocumentType::OpeningBalance)
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('puts the difference into opening balance equity', function (): void {
        $entry = $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
            $this->payables->getKey() => '120000.00',
        ], $this->owner);

        $equity = app(ChartOfAccountsService::class)
            ->systemAccount($this->company, Account::OPENING_BALANCE_EQUITY);

        $equityLine = $entry->lines->firstWhere('account_id', $equity?->getKey());

        // Assets 500,000 less liabilities 120,000 is 380,000 of equity. An accountant reclassifies it
        // afterwards — into share capital, retained earnings, a director's loan — which is exactly the
        // judgement the platform should not make for them.
        expect($equityLine?->credit)->toBe('380000.0000');
    });

    it('debits equity when liabilities exceed assets', function (): void {
        $entry = $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '50000.00',
            $this->payables->getKey() => '120000.00',
        ], $this->owner);

        $equity = app(ChartOfAccountsService::class)
            ->systemAccount($this->company, Account::OPENING_BALANCE_EQUITY);

        // A business arriving insolvent is unusual and entirely legal. The sign carries it without a
        // special case.
        expect($entry->lines->firstWhere('account_id', $equity?->getKey())?->debit)->toBe('70000.0000');
    });

    it('reads a negative balance as the opposite side', function (): void {
        $entry = $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            // An overdrawn bank account: an asset with a credit balance.
            $this->cash->getKey() => '-25000.00',
        ], $this->owner);

        expect($entry->lines->firstWhere('account_id', $this->cash->getKey())?->credit)->toBe('25000.0000');
    });

    it('skips zero balances rather than writing empty lines', function (): void {
        $entry = $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
            $this->rent->getKey() => '0.00',
        ], $this->owner);

        // A zero opening balance is the absence of one. A line of zero would fail the one-sided check
        // for no reason the user could act on.
        expect($entry->lines->pluck('account_id')->all())->not->toContain($this->rent->getKey());
    });

    it('refuses a second opening entry', function (): void {
        $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
        ], $this->owner);

        // The one that matters. A second opening entry doubles the company's starting position, and
        // because both entries balance, nothing reports a problem — the business simply appears to
        // have twice the assets it started with.
        expect(catchPlatformException(fn () => $this->opening->record(
            $this->company,
            CarbonImmutable::parse('2026-06-01'),
            [$this->cash->getKey() => '100.00'],
            $this->owner,
        ))->problemCode())->toBe('opening-balances-already-recorded');
    });

    it('refuses a directly-entered equity line', function (): void {
        $equity = app(ChartOfAccountsService::class)
            ->systemAccount($this->company, Account::OPENING_BALANCE_EQUITY);

        // The equity line is derived. Accepting one would let a caller declare a balancing figure that
        // does not balance, and the entry would then be rejected for a reason that makes no sense.
        expect(catchPlatformException(fn () => $this->opening->record(
            $this->company,
            CarbonImmutable::parse('2026-06-01'),
            [$this->cash->getKey() => '100.00', $equity?->getKey() => '100.00'],
            $this->owner,
        ))->problemCode())->toBe('opening-balance-equity-is-derived');
    });

    it('refuses an account from another company', function (): void {
        $second = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->owner,
        );
        app(ChartTemplateService::class)->apply($second);

        $foreign = Account::query()->forCompany($second->getKey())->where('code', '1110')->firstOrFail();

        expect(catchPlatformException(fn () => $this->opening->record(
            $this->company,
            CarbonImmutable::parse('2026-06-01'),
            [$foreign->getKey() => '100.00'],
            $this->owner,
        ))->problemCode())->toBe('unknown-opening-balance-account');
    });

    it('reports whether an opening position has been recorded', function (): void {
        expect($this->opening->hasBeenRecorded($this->company))->toBeFalse();

        $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
        ], $this->owner);

        expect($this->opening->hasBeenRecorded($this->company))->toBeTrue();
    });

    it('appears in the trial balance', function (): void {
        $this->opening->record($this->company, CarbonImmutable::parse('2026-06-01'), [
            $this->cash->getKey() => '500000.00',
            $this->payables->getKey() => '120000.00',
        ], $this->owner);

        $rows = $this->balances->trialBalance($this->company, DateRange::fromStrings('2026-06-01', '2026-06-30'));

        expect($this->balances->trialBalanceTies($rows, 'LKR'))->toBeTrue()
            ->and(collect($rows)->pluck('account.code')->all())->toContain('1110', '2110', '3300');
    });
});

describe('closing a period', function (): void {
    it('closes an open period', function (): void {
        $closed = closeThrough('2026-06-15');

        expect($closed->status)->toBe(PeriodStatus::Closed)
            ->and($closed->closed_at)->not->toBeNull()
            ->and($closed->closed_by_id)->toBe($this->owner->getKey());
    });

    it('stops postings into it', function (): void {
        postEntry($this->cash->getKey(), $this->sales->getKey(), '100.00', '2026-06-10');

        closeThrough('2026-06-15');

        expect(catchPlatformException(
            fn () => postEntry($this->cash->getKey(), $this->sales->getKey(), '100.00', '2026-06-20'),
        )->problemCode())->toBe('period-not-open');
    });

    it('refuses to close out of order', function (): void {
        // A closed March with an open February lets someone post into February and change the
        // year-to-date figures March's close was supposed to fix.
        $exception = catchPlatformException(fn () => $this->close->close(periodOn('2026-08-15'), $this->owner));

        expect($exception->problemCode())->toBe('earlier-period-still-open');
    });

    it('refuses to close a period holding drafts', function (): void {
        app(JournalService::class)->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Unfinished',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('50.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('50.00', 'LKR')),
            ],
        ));

        // A draft in a closed period can never be posted, so closing over it silently discards
        // someone's work. They should decide whether it matters.
        expect(catchPlatformException(fn () => closeThrough('2026-06-15'))->problemCode())
            ->toBe('period-has-drafts');
    });

    it('is idempotent', function (): void {
        $period = closeThrough('2026-06-15');

        expect($this->close->close($period, $this->owner)->status)->toBe(PeriodStatus::Closed);
    });
});

describe('reopening a period', function (): void {
    it('reopens a closed period and records why', function (): void {
        $period = closeThrough('2026-06-15');

        $reopened = $this->close->reopen($period, 'Supplier invoice arrived late', $this->owner);

        // The reason is the point. Reopening changes figures that may already have been filed, and
        // "why" is the first question an auditor asks.
        expect($reopened->status)->toBe(PeriodStatus::Open)
            ->and($reopened->reopen_reason)->toBe('Supplier invoice arrived late')
            ->and($reopened->reopened_by_id)->toBe($this->owner->getKey())
            ->and($reopened->closed_at)->toBeNull();
    });

    it('accepts postings again once reopened', function (): void {
        $period = closeThrough('2026-06-15');
        $this->close->reopen($period, 'Late invoice', $this->owner);

        // A period that could never be reopened would make an error found in month three permanent,
        // which is worse than the risk closing protects against.
        expect(postEntry($this->cash->getKey(), $this->sales->getKey(), '100.00', '2026-06-20')->number)
            ->not->toBeNull();
    });

    it('requires a reason', function (): void {
        $period = closeThrough('2026-06-15');

        expect(catchPlatformException(fn () => $this->close->reopen($period, '   ', $this->owner))->problemCode())
            ->toBe('reopen-reason-required');
    });

    it('refuses to reopen a locked period', function (): void {
        // Closed first, then locked — the sequence a year-end close actually produces. A period
        // locked without ever being closed would violate `fiscal_periods_closed_check`, which is the
        // database saying the same thing.
        $period = closeThrough('2026-06-15');
        $period->status = PeriodStatus::Locked;
        $period->save();

        // Locked is stronger than closed: the year has been closed, and reopening would leave retained
        // earnings holding a figure that no longer matches the trading it summarises.
        expect(catchPlatformException(fn () => $this->close->reopen($period, 'Because', $this->owner))->problemCode())
            ->toBe('period-locked');
    });

    it('refuses a reason-less reopening at the database', function (): void {
        $period = closeThrough('2026-06-15');

        // The service asks for a reason; this is what catches a console command or a data fix that
        // does not.
        expect(fn () => DB::table('fiscal_periods')
            ->where('id', $period->getKey())
            ->update(['reopened_at' => now(), 'status' => 'open']))
            ->toThrow(QueryException::class);
    });
});

describe('closing a year', function (): void {
    beforeEach(function (): void {
        // A year of trading: 300,000 of sales against 110,000 of rent. Net profit 190,000.
        postEntry($this->cash->getKey(), $this->sales->getKey(), '300000.00', '2026-06-10');
        postEntry($this->rent->getKey(), $this->cash->getKey(), '110000.00', '2026-07-10');

        $this->year = FiscalYear::query()->forCompany($this->company->getKey())->firstOrFail();
    });

    it('reports the net result before committing to it', function (): void {
        // What the close *would* post, so an accountant can look at the figure first.
        expect($this->close->netResultFor($this->year)->toDecimalString())->toBe('190000.0000');
    });

    it('refuses while any period is still open', function (): void {
        expect(catchPlatformException(fn () => $this->close->closeYear($this->year, $this->owner))->problemCode())
            ->toBe('year-has-open-periods');
    });

    it('moves the net result to retained earnings', function (): void {
        closeEveryPeriod();

        $entry = $this->close->closeYear($this->year, $this->owner);

        $retained = app(ChartOfAccountsService::class)
            ->systemAccount($this->company, Account::RETAINED_EARNINGS);

        expect($entry?->lines->firstWhere('account_id', $retained?->getKey())?->credit)->toBe('190000.0000');
    });

    it('zeroes every income and expense account', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        $rows = collect($this->balances->trialBalance(
            $this->company,
            DateRange::between($this->year->starts_on, $this->year->ends_on),
        ))->keyBy('account.code');

        // Income and expenses measure one year's trading. Carrying them forward would accumulate
        // since the company was founded.
        expect($rows['4100']['balance']->isZero())->toBeTrue()
            ->and($rows['6200']['balance']->isZero())->toBeTrue();
    });

    it('leaves balance sheet accounts untouched', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        $rows = collect($this->balances->trialBalance(
            $this->company,
            DateRange::between($this->year->starts_on, $this->year->ends_on),
        ))->keyBy('account.code');

        // Cash is 300,000 in less 110,000 out. Zeroing it would erase the company's position rather
        // than its trading.
        expect($rows['1110']['balance']->toDecimalString())->toBe('190000.0000');
    });

    it('still ties after closing', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        $rows = $this->balances->trialBalance(
            $this->company,
            DateRange::between($this->year->starts_on, $this->year->ends_on),
        );

        expect($this->balances->trialBalanceTies($rows, 'LKR'))->toBeTrue();
    });

    it('debits retained earnings for a loss', function (): void {
        // More rent than sales in a second company, so the net is negative.
        postEntry($this->rent->getKey(), $this->cash->getKey(), '400000.00', '2026-08-10');

        closeEveryPeriod();

        $entry = $this->close->closeYear($this->year, $this->owner);

        $retained = app(ChartOfAccountsService::class)
            ->systemAccount($this->company, Account::RETAINED_EARNINGS);

        // 300,000 income less 510,000 expenses is a loss of 210,000. The sign carries it.
        expect($entry?->lines->firstWhere('account_id', $retained?->getKey())?->debit)->toBe('210000.0000');
    });

    it('locks every period of the closed year', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        $statuses = FiscalPeriod::query()
            ->where('fiscal_year_id', $this->year->getKey())
            ->pluck('status')
            ->unique()
            ->all();

        // Locked, not closed: a closed period can be reopened by a controller, and reopening a period
        // whose year is closed would leave retained earnings holding a stale figure.
        expect($statuses)->toBe([PeriodStatus::Locked]);
    });

    it('links the closing entry to the year', function (): void {
        closeEveryPeriod();

        $entry = $this->close->closeYear($this->year, $this->owner);

        // Reversing that entry is the documented route to undoing a close. Without the link, finding
        // it means searching the journal by date and hoping there is only one.
        expect($this->year->fresh()?->closing_entry_id)->toBe($entry?->getKey());
    });

    it('refuses to close a year twice', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        expect(catchPlatformException(fn () => $this->close->closeYear($this->year->fresh(), $this->owner))->problemCode())
            ->toBe('year-already-closed');
    });

    it('closes a year with no trading without writing an entry', function (): void {
        $quiet = $this->calendar->openYearContaining($this->company, CarbonImmutable::parse('2027-06-15'));

        // 2026 first. Periods close in order across the whole company, not merely within a year — a
        // later year closed over an open earlier one would let a posting into 2026 change figures
        // that 2027's close had already summarised.
        closeEveryPeriod();

        foreach ($quiet->periods()->get() as $period) {
            $this->close->close($period, $this->owner);
        }

        // A zero entry would be noise in the ledger and would still have to balance against nothing.
        expect($this->close->closeYear($quiet, $this->owner))->toBeNull()
            ->and($quiet->fresh()?->isClosed())->toBeTrue();
    });

    it('is undone by reversing the closing entry', function (): void {
        closeEveryPeriod();

        $entry = $this->close->closeYear($this->year, $this->owner);

        // Unlocked so the reversal can post — the same step an operator takes, made explicit. Both
        // columns move together because `fiscal_periods_closed_check` requires it: an open period
        // carries no closing timestamp.
        FiscalPeriod::query()->where('fiscal_year_id', $this->year->getKey())
            ->update(['status' => PeriodStatus::Open->value, 'closed_at' => null]);

        $this->posting->reverse($entry, 'Closed a year early', CarbonImmutable::parse('2026-06-30'), $this->owner);

        $rows = collect($this->balances->trialBalance(
            $this->company,
            DateRange::between($this->year->starts_on, $this->year->ends_on),
        ))->keyBy('account.code');

        // Income is back where it was. Closing a year is an ordinary reversible entry, which is what
        // makes it safe to do on judgement rather than requiring certainty.
        expect($rows['4100']['balance']->toDecimalString())->toBe('300000.0000');
    });

    it('keeps the aggregates in step through a close', function (): void {
        closeEveryPeriod();

        $this->close->closeYear($this->year, $this->owner);

        expect($this->balances->drift($this->company))->toBe([]);
    });
});

/**
 * Closes every period of the company's first year, in order.
 *
 * In order because the service requires it — a closed period with an earlier open one lets a later
 * posting change the figures the close was meant to fix.
 */
function closeEveryPeriod(): void
{
    $periods = FiscalPeriod::query()
        ->forCompany(test()->company->getKey())
        ->where('fiscal_year_id', test()->year->getKey())
        ->orderBy('starts_on')
        ->get();

    foreach ($periods as $period) {
        test()->close->close($period, test()->owner);
    }
}
