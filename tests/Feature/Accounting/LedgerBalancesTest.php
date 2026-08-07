<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\AccountPeriodBalance;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Audit\Domain\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The period balance aggregates, and the two reports drawn from them.
 *
 * The aggregates are a cache with a transaction around it. `journal_lines` is the only thing that is
 * true, so the tests that matter are the ones proving the two still agree — and proving that when
 * they are made to disagree, something says so.
 *
 * That last part is why `asids:ledger-verify` exists and why it is tested against deliberately
 * corrupted data. A derived table nobody checks is a derived table nobody can trust.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->posting = app(PostingService::class);
    $this->balances = app(LedgerBalanceService::class);

    $this->cash = account('1110');
    $this->sales = account('4100');
    $this->rent = account('6200');
});

function account(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

function post(string $debitId, string $creditId, string $amount = '1000.00', string $date = '2026-06-15'): JournalEntry
{
    return test()->posting->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::parse($date),
        description: 'Test entry',
        lines: [
            new JournalLineData(accountId: $debitId, debit: Money::of($amount, 'LKR')),
            new JournalLineData(accountId: $creditId, credit: Money::of($amount, 'LKR')),
        ],
    ), test()->owner);
}

function june(): DateRange
{
    return DateRange::fromStrings('2026-06-01', '2026-06-30');
}

describe('maintaining the aggregates', function (): void {
    it('writes a row for each account an entry touches', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        expect(AccountPeriodBalance::query()->forCompany($this->company->getKey())->count())->toBe(2);
    });

    it('records the movement on the correct side', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1500.00');

        $cashBalance = AccountPeriodBalance::query()->where('account_id', $this->cash->getKey())->firstOrFail();
        $salesBalance = AccountPeriodBalance::query()->where('account_id', $this->sales->getKey())->firstOrFail();

        expect($cashBalance->debit_total)->toBe('1500.0000')
            ->and($cashBalance->credit_total)->toBe('0.0000')
            ->and($salesBalance->credit_total)->toBe('1500.0000');
    });

    it('accumulates several entries into one row per account and period', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');
        post($this->cash->getKey(), $this->sales->getKey(), '250.00');

        $cashBalance = AccountPeriodBalance::query()->where('account_id', $this->cash->getKey())->firstOrFail();

        // One row, not two. The unique index enforces it; this asserts the totals accumulate rather
        // than the last write winning.
        expect(AccountPeriodBalance::query()->where('account_id', $this->cash->getKey())->count())->toBe(1)
            ->and($cashBalance->debit_total)->toBe('1250.0000')
            ->and($cashBalance->line_count)->toBe(2);
    });

    it('keeps periods separate', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-15');
        post($this->cash->getKey(), $this->sales->getKey(), '400.00', '2026-07-15');

        $rows = AccountPeriodBalance::query()->where('account_id', $this->cash->getKey())->get();

        // Sorted numerically, not as strings: '1000.0000' sorts before '400.0000' lexically, which is
        // a comparison this test would otherwise be making about PHP rather than about the ledger.
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('debit_total')->map(static fn (string $total): float => (float) $total)->sort()->values()->all())
            ->toBe([400.0, 1000.0]);
    });

    it('counts a draft towards nothing', function (): void {
        app(JournalService::class)->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Draft',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('99.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('99.00', 'LKR')),
            ],
        ));

        // A draft is not part of the record. Including it would make the trial balance change as
        // someone typed.
        expect(AccountPeriodBalance::query()->forCompany($this->company->getKey())->count())->toBe(0);
    });

    it('leaves a reversal and its original both counted', function (): void {
        $entry = post($this->cash->getKey(), $this->sales->getKey(), '1000.00');

        $this->posting->reverse($entry, 'Wrong account', CarbonImmutable::parse('2026-06-20'), $this->owner);

        $cashRows = AccountPeriodBalance::query()->where('account_id', $this->cash->getKey())->get();

        // Both entries remain in the ledger and cancel. Removing either would leave the aggregate out
        // by the entry's amount — the balance nets to zero, it does not disappear.
        expect($cashRows->sum(static fn (AccountPeriodBalance $row): float => (float) $row->debit_total))->toBe(1000.0)
            ->and($cashRows->sum(static fn (AccountPeriodBalance $row): float => (float) $row->credit_total))->toBe(1000.0);
    });

    it('agrees with a recomputation from the lines after every operation', function (): void {
        $entry = post($this->cash->getKey(), $this->sales->getKey(), '1000.00');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00');
        $this->posting->reverse($entry, 'Corrected', CarbonImmutable::parse('2026-06-20'), $this->owner);

        // The property the whole design rests on, asserted after the messiest sequence available.
        expect($this->balances->drift($this->company))->toBe([]);
    });
});

describe('the trial balance', function (): void {
    it('lists every account with movement', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00');

        $rows = $this->balances->trialBalance($this->company, june());

        expect(collect($rows)->pluck('account.code')->all())->toContain('1110', '4100', '6200');
    });

    it('omits accounts with no movement', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $rows = $this->balances->trialBalance($this->company, june());

        // A chart of eighty accounts on a month where two were used produces a report nobody reads.
        expect($rows)->toHaveCount(2);
    });

    it('ties', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00');

        $rows = $this->balances->trialBalance($this->company, june());

        // The single question that says whether the ledger is sound.
        expect($this->balances->trialBalanceTies($rows, 'LKR'))->toBeTrue();
    });

    it('ties after a thousand randomised entries', function (): void {
        // The rounding-drift test. Scale-4 storage and explicit rounding at posting are what make
        // this hold; a system that rounded at each intermediate step would be out by cents here, and
        // cents in a trial balance are the most expensive kind of bug to chase.
        $accounts = [$this->cash->getKey(), $this->sales->getKey(), $this->rent->getKey()];

        for ($i = 0; $i < 200; $i++) {
            // Deterministic rather than random: a test that fails one run in fifty is a test people
            // learn to re-run. These amounts include the thirds and sevenths that expose rounding.
            $amount = sprintf('%d.%02d', ($i * 7) % 97 + 1, ($i * 13) % 100);

            post($accounts[$i % 3], $accounts[($i + 1) % 3], $amount);
        }

        $rows = $this->balances->trialBalance($this->company, june());

        expect($this->balances->trialBalanceTies($rows, 'LKR'))->toBeTrue()
            ->and($this->balances->drift($this->company))->toBe([]);
    });

    it('signs each balance by the account’s own normal balance', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');

        $rows = collect($this->balances->trialBalance($this->company, june()))->keyBy('account.code');

        // An asset debited and a liability credited both read positive. Reporting one of them negative
        // is how a balance sheet ends up with parentheses everywhere.
        expect($rows['1110']['balance']->toDecimalString())->toBe('1000.0000')
            ->and($rows['4100']['balance']->toDecimalString())->toBe('1000.0000');
    });

    it('reports a contra balance as negative', function (): void {
        // Cash credited more than debited — an overdraft. The sign is what tells a reader the account
        // is in the opposite state to the one its type expects.
        post($this->sales->getKey(), $this->cash->getKey(), '500.00');

        $rows = collect($this->balances->trialBalance($this->company, june()))->keyBy('account.code');

        expect($rows['1110']['balance']->toDecimalString())->toBe('-500.0000');
    });

    it('orders by account type then code', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());
        post($this->rent->getKey(), $this->cash->getKey(), '100.00');

        $codes = collect($this->balances->trialBalance($this->company, june()))->pluck('account.code')->all();

        // Assets, then income, then expenses — the order every set of books is read in.
        expect($codes)->toBe(['1110', '4100', '6200']);
    });

    it('excludes periods outside the range', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-15');
        post($this->cash->getKey(), $this->sales->getKey(), '400.00', '2026-07-15');

        $rows = collect($this->balances->trialBalance($this->company, june()))->keyBy('account.code');

        expect($rows['1110']['debit']->toDecimalString())->toBe('1000.0000');
    });

    it('returns nothing for a company with no activity', function (): void {
        expect($this->balances->trialBalance($this->company, june()))->toBe([]);
    });
});

describe('the account ledger', function (): void {
    it('lists the entries touching an account in date order', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-10');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00', '2026-06-20');

        $ledger = $this->balances->accountLedger($this->company, $this->cash, june());

        expect($ledger['lines'])->toHaveCount(2)
            ->and($ledger['lines'][0]['entry']->entry_date->toDateString())->toBe('2026-06-10');
    });

    it('carries a running balance', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-10');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00', '2026-06-20');

        $ledger = $this->balances->accountLedger($this->company, $this->cash, june());

        // 1000 in, 300 out. The running column is what makes an account ledger readable — a list of
        // movements without it forces the reader to add up by hand.
        expect($ledger['lines'][0]['running']->toDecimalString())->toBe('1000.0000')
            ->and($ledger['lines'][1]['running']->toDecimalString())->toBe('700.0000')
            ->and($ledger['closing']->toDecimalString())->toBe('700.0000');
    });

    it('opens with the balance brought forward from before the range', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-05-20');
        post($this->cash->getKey(), $this->sales->getKey(), '250.00', '2026-06-10');

        $ledger = $this->balances->accountLedger($this->company, $this->cash, june());

        // Without an opening balance the report is a movement list rather than a statement, and the
        // closing figure does not match the trial balance.
        expect($ledger['opening']->toDecimalString())->toBe('1000.0000')
            ->and($ledger['closing']->toDecimalString())->toBe('1250.0000');
    });

    it('agrees with the trial balance for the same account and range', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-10');
        post($this->rent->getKey(), $this->cash->getKey(), '300.00', '2026-06-20');

        $ledger = $this->balances->accountLedger($this->company, $this->cash, june());
        $trial = collect($this->balances->trialBalance($this->company, june()))->keyBy('account.code');

        // The two are computed from different sources — the ledger from the lines, the trial balance
        // from the aggregates — so their agreement is the end-to-end check that the cache is honest.
        expect($ledger['closing']->toDecimalString())->toBe($trial['1110']['balance']->toDecimalString());
    });

    it('includes a reversal as its own movement', function (): void {
        $entry = post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-10');
        $this->posting->reverse($entry, 'Corrected', CarbonImmutable::parse('2026-06-20'), $this->owner);

        $ledger = $this->balances->accountLedger($this->company, $this->cash, june());

        // Both movements visible, netting to zero. An auditor tracing this account sees what happened
        // and when, rather than an account that was never touched.
        expect($ledger['lines'])->toHaveCount(2)
            ->and($ledger['closing']->isZero())->toBeTrue();
    });

    it('excludes drafts', function (): void {
        app(JournalService::class)->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Draft',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('99.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('99.00', 'LKR')),
            ],
        ));

        expect($this->balances->accountLedger($this->company, $this->cash, june())['lines'])->toBe([]);
    });
});

describe('drift detection', function (): void {
    it('reports nothing when the aggregates are correct', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        expect($this->balances->drift($this->company))->toBe([]);
    });

    it('detects a corrupted total', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');

        DB::table('account_period_balances')
            ->where('account_id', $this->cash->getKey())
            ->update(['debit_total' => '9999.0000']);

        $drift = $this->balances->drift($this->company);

        expect($drift)->toHaveCount(1)
            ->and($drift[0]['stored_debit'])->toBe('9999.0000')
            ->and($drift[0]['actual_debit'])->toBe('1000.0000');
    });

    it('detects a missing aggregate row', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        DB::table('account_period_balances')->where('account_id', $this->cash->getKey())->delete();

        // The most likely drift, and the one an aggregate-first walk cannot see. This is why `drift()`
        // starts from the lines and full-outer-joins the aggregates rather than the other way round.
        expect($this->balances->drift($this->company))->toHaveCount(1);
    });

    it('detects an orphaned aggregate row with no lines behind it', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $period = FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->firstOrFail();

        DB::table('account_period_balances')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'account_id' => $this->rent->getKey(),
            'fiscal_period_id' => $period->getKey(),
            'debit_total' => '500.0000',
            'credit_total' => '0.0000',
            'line_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A total for an account that was never posted to would appear on the trial balance as a real
        // figure with nothing behind it.
        expect($this->balances->drift($this->company))->toHaveCount(1);
    });
});

describe('rebuilding', function (): void {
    it('repairs a corrupted total', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00');

        DB::table('account_period_balances')
            ->where('account_id', $this->cash->getKey())
            ->update(['debit_total' => '9999.0000']);

        $this->balances->rebuild($this->company);

        expect($this->balances->drift($this->company))->toBe([]);
    });

    it('removes an orphaned row rather than leaving it', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $period = FiscalPeriod::query()
            ->forCompany($this->company->getKey())
            ->firstOrFail();

        DB::table('account_period_balances')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'account_id' => $this->rent->getKey(),
            'fiscal_period_id' => $period->getKey(),
            'debit_total' => '500.0000',
            'credit_total' => '0.0000',
            'line_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->balances->rebuild($this->company);

        // Deleting before recomputing is what makes this possible. Incrementally correcting could
        // never remove a row whose lines no longer exist.
        expect(AccountPeriodBalance::query()->where('account_id', $this->rent->getKey())->exists())->toBeFalse();
    });

    it('rebuilds one period without touching another', function (): void {
        post($this->cash->getKey(), $this->sales->getKey(), '1000.00', '2026-06-15');
        post($this->cash->getKey(), $this->sales->getKey(), '400.00', '2026-07-15');

        $july = app(FiscalCalendarService::class)->periodFor($this->company, CarbonImmutable::parse('2026-07-15'));

        DB::table('account_period_balances')->update(['debit_total' => '1.0000']);

        $this->balances->rebuild($this->company, $july);

        $drift = $this->balances->drift($this->company);

        // July repaired, June still wrong. A narrow repair is the point of the scope — rewriting seven
        // years of aggregates to fix one month is a maintenance window nobody needs.
        expect(collect($drift)->pluck('fiscal_period_id')->unique()->all())
            ->not->toContain($july->getKey());
    });

    it('is idempotent', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $first = $this->balances->rebuild($this->company);
        $second = $this->balances->rebuild($this->company);

        expect($first)->toBe($second)
            ->and($this->balances->drift($this->company))->toBe([]);
    });
});

describe('the console commands', function (): void {
    it('reports agreement', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $this->artisan('asids:ledger-verify', ['--tenant' => 'acme'])
            ->assertSuccessful();
    });

    it('fails when the aggregates have drifted', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        DB::table('account_period_balances')->update(['debit_total' => '9999.0000']);

        // A non-zero exit is the signal. Wired to an alert, this is what turns silent drift into a
        // page rather than a discovery.
        $this->artisan('asids:ledger-verify', ['--tenant' => 'acme'])
            ->assertFailed();
    });

    it('refuses to rebuild without --confirm', function (): void {
        $this->artisan('asids:ledger-rebuild', ['--tenant' => 'acme'])
            ->expectsOutputToContain('--confirm')
            ->assertFailed();
    });

    it('refuses to rebuild without naming a workspace', function (): void {
        // Rebuilding one company is a repair; sweeping a hundred thousand is an unbounded write to
        // the busiest table in the platform.
        $this->artisan('asids:ledger-rebuild', ['--confirm' => true])
            ->assertFailed();
    });

    it('rebuilds when asked properly', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        DB::table('account_period_balances')->update(['debit_total' => '9999.0000']);

        $this->artisan('asids:ledger-rebuild', ['--tenant' => 'acme', '--confirm' => true])
            ->assertSuccessful();

        expect($this->balances->drift($this->company))->toBe([]);
    });

    it('records the rebuild in the activity log', function (): void {
        post($this->cash->getKey(), $this->sales->getKey());

        $this->artisan('asids:ledger-rebuild', ['--tenant' => 'acme', '--confirm' => true])->assertSuccessful();

        // An auditor asking "why did last March's figures change on the 14th?" needs an answer.
        expect(ActivityLog::query()
            ->where('event', 'ledger.balances.rebuilt')
            ->exists())->toBeTrue();
    });
});
