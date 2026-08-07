<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Enums\JournalEntryStatus;
use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\Journal;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ledger.
 *
 * The centre of the phase, and the tests that matter most in it. Three properties, and each is
 * asserted twice — once through the service, where the customer gets a sentence, and once against
 * raw SQL, where the database is the only thing left:
 *
 *   1. **Debits equal credits.** An unbalanced ledger cannot be repaired without knowing which side
 *      was wrong, and by the time anyone notices, nobody does.
 *   2. **A posted entry never changes.** Corrections are reversing entries, so an auditor sees the
 *      mistake and the remedy rather than a tidy history in which the mistake never happened.
 *   3. **Document numbers have no gaps.** A missing number in an auditable series is a question a
 *      customer has to answer to a tax authority with no evidence available either way.
 *
 * The raw-SQL half is not belt and braces. Phase 1's severest defects were all cases where the
 * application's own path was correct and something else — a console command, a package's gate hook,
 * an environment setting — was not.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->journals = app(JournalService::class);
    $this->posting = app(PostingService::class);

    $this->cash = accountByCode('1110');
    $this->sales = accountByCode('4100');
    $this->rent = accountByCode('6200');
});

function accountByCode(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

/**
 * A balanced two-line entry: debit one account, credit another.
 */
function entryData(
    string $debitAccountId,
    string $creditAccountId,
    string $amount = '1000.00',
    string $date = '2026-06-15',
): JournalEntryData {
    return new JournalEntryData(
        entryDate: CarbonImmutable::parse($date),
        description: 'Test entry',
        lines: [
            new JournalLineData(accountId: $debitAccountId, debit: Money::of($amount, 'LKR')),
            new JournalLineData(accountId: $creditAccountId, credit: Money::of($amount, 'LKR')),
        ],
    );
}

describe('drafting', function (): void {
    it('creates a draft with its lines', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect($entry->status)->toBe(JournalEntryStatus::Draft)
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->number)->toBeNull();
    });

    it('resolves the fiscal period from the entry date', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect($entry->fiscalPeriod->label)->toBe('June 2026');
    });

    it('refuses a date with no fiscal period, at drafting rather than at posting', function (): void {
        // Told while the user is still looking at the form, not days later when someone tries to
        // post it.
        expect(catchPlatformException(fn () => $this->journals->draft(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey(), date: '2035-01-15'),
        ))->problemCode())->toBe('no-fiscal-period');
    });

    it('numbers lines in the order they were given', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect($entry->lines->pluck('line_number')->all())->toBe([1, 2]);
    });

    it('creates the general journal on first use', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect($entry->journal->is_general)->toBeTrue()
            ->and($entry->journal->code)->toBe('GJ');
    });

    it('reuses the general journal rather than creating a second', function (): void {
        $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));
        $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect(Journal::query()->forCompany($this->company->getKey())->count())
            ->toBe(1);
    });

    it('refuses a line naming a heading account', function (): void {
        $heading = accountByCode('1000');

        // Posting to a heading turns a chart of accounts into a chart of subtotals nobody can
        // reconcile.
        expect(catchPlatformException(fn () => $this->journals->draft(
            $this->company,
            entryData($heading->getKey(), $this->sales->getKey()),
        ))->problemCode())->toBe('account-not-postable');
    });

    it('refuses a line naming an archived account', function (): void {
        app(ChartOfAccountsService::class)->archive($this->rent);

        expect(catchPlatformException(fn () => $this->journals->draft(
            $this->company,
            entryData($this->rent->getKey(), $this->sales->getKey()),
        ))->problemCode())->toBe('account-archived');
    });

    it('refuses a line naming another company’s account', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $foreign = Account::query()->forCompany($second->getKey())->where('code', '1110')->firstOrFail();

        expect(catchPlatformException(fn () => $this->journals->draft(
            $this->company,
            entryData($foreign->getKey(), $this->sales->getKey()),
        ))->problemCode())->toBe('account-foreign-company');
    });

    it('refuses a line with both a debit and a credit', function (): void {
        // Ambiguous: is it a net movement, or two movements on one row?
        expect(catchPlatformException(fn () => $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Both sides',
            lines: [
                new JournalLineData(
                    accountId: $this->cash->getKey(),
                    debit: Money::of('100.00', 'LKR'),
                    credit: Money::of('100.00', 'LKR'),
                ),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('100.00', 'LKR')),
            ],
        )))->problemCode())->toBe('line-not-one-sided');
    });

    it('refuses a negative line amount', function (): void {
        // A negative debit is a credit written confusingly. Allowing it would make every report that
        // sums a side wrong in a way that still balances.
        expect(catchPlatformException(fn () => $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Negative',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('-100.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('-100.00', 'LKR')),
            ],
        )))->problemCode())->toBe('negative-line-amount');
    });

    it('permits an unbalanced draft', function (): void {
        // A half-entered entry on screen is legitimately unbalanced. Refusing to save it would mean
        // losing work every time the phone rings.
        $entry = $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Half entered',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('100.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('60.00', 'LKR')),
            ],
        ));

        expect($entry->status)->toBe(JournalEntryStatus::Draft)
            ->and($entry->isBalanced())->toBeFalse();
    });

    it('replaces a draft’s lines wholesale rather than merging them', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        $updated = $this->journals->updateDraft($entry, entryData($this->rent->getKey(), $this->cash->getKey(), '250.00'));

        // "These are its lines now" is what the user means on save. Matching submitted rows against
        // stored ones by position is how a reordered line becomes an edit of a different account.
        expect($updated->lines)->toHaveCount(2)
            ->and($updated->lines->pluck('account_id')->all())
            ->toBe([$this->rent->getKey(), $this->cash->getKey()]);
    });

    it('deletes a draft and its lines', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        $this->journals->deleteDraft($entry);

        expect(JournalEntry::query()->whereKey($entry->getKey())->exists())->toBeFalse()
            ->and(JournalLine::query()->where('journal_entry_id', $entry->getKey())->exists())->toBeFalse();
    });
});

describe('posting', function (): void {
    it('posts a balanced entry and issues a number', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        $posted = $this->posting->post($entry, $this->owner);

        expect($posted->status)->toBe(JournalEntryStatus::Posted)
            ->and($posted->number)->toBe('JV-2026-06-0001')
            ->and($posted->posted_at)->not->toBeNull()
            ->and($posted->posted_by_id)->toBe($this->owner->getKey());
    });

    it('refuses to post an unbalanced entry, naming the difference', function (): void {
        $entry = $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Unbalanced',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('100.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('60.00', 'LKR')),
            ],
        ));

        $exception = catchPlatformException(fn () => $this->posting->post($entry, $this->owner));

        // The message names the amount, not a constraint. "Debits total 100.0000 and credits total
        // 60.0000 — a difference of 40.0000" is something a bookkeeper can act on.
        expect($exception->problemCode())->toBe('unbalanced-entry')
            ->and($exception->getMessage())->toContain('40.0000');
    });

    it('refuses to post an entry with a single line', function (): void {
        $entry = $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'One line',
            lines: [new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('100.00', 'LKR'))],
        ));

        expect(catchPlatformException(fn () => $this->posting->post($entry, $this->owner))->problemCode())
            ->toBe('entry-has-one-line');
    });

    it('refuses to post into a closed period', function (): void {
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        DB::table('fiscal_periods')
            ->where('id', $entry->fiscal_period_id)
            ->update(['status' => PeriodStatus::Closed->value, 'closed_at' => now()]);

        expect(catchPlatformException(fn () => $this->posting->post($entry->fresh(), $this->owner))->problemCode())
            ->toBe('period-not-open');
    });

    it('refuses to post the same entry twice', function (): void {
        $entry = $this->posting->post(
            $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey())),
            $this->owner,
        );

        expect(catchPlatformException(fn () => $this->posting->post($entry, $this->owner))->problemCode())
            ->toBe('entry-already-posted');
    });

    it('drafts and posts in one call', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        expect($entry->status)->toBe(JournalEntryStatus::Posted)->and($entry->number)->not->toBeNull();
    });

    it('rounds amounts to the company’s currency precision', function (): void {
        $entry = $this->posting->postNew($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Sub-cent amounts',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('100.0050', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('100.0050', 'LKR')),
            ],
        ), $this->owner);

        // The ledger holds amounts that exist in the currency. A line of LKR 100.0050 is not a number
        // anyone can pay, and the rounding happens once, at posting, rather than at each read.
        expect($entry->lines->first()?->debit)->toBe('100.0100');
    });

    it('consumes no number when posting fails', function (): void {
        $failing = $this->journals->draft($this->company, new JournalEntryData(
            entryDate: CarbonImmutable::parse('2026-06-15'),
            description: 'Unbalanced',
            lines: [
                new JournalLineData(accountId: $this->cash->getKey(), debit: Money::of('100.00', 'LKR')),
                new JournalLineData(accountId: $this->sales->getKey(), credit: Money::of('60.00', 'LKR')),
            ],
        ));

        try {
            $this->posting->post($failing, $this->owner);
        } catch (Throwable) {
            // Expected.
        }

        $succeeding = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // The first number, not the second. A failed document must not consume a number, or the
        // series has a gap nobody can account for.
        expect($succeeding->number)->toBe('JV-2026-06-0001');
    });
});

describe('the balance rule at the database', function (): void {
    it('refuses an unbalanced entry inserted by raw SQL', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // The service is bypassed entirely. This is what protects the ledger from a console command,
        // a data fix, or a future module that does not know the rule.
        //
        // `SET CONSTRAINTS ALL IMMEDIATE` is required and is not a workaround for a weak test: the
        // trigger is DEFERRABLE INITIALLY DEFERRED and therefore fires at COMMIT, and `RefreshDatabase`
        // wraps every test in a transaction that is rolled back rather than committed — so a deferred
        // check would never run here at all, and this test would pass against a database with no
        // trigger on it. Forcing the check to immediate is what makes the assertion real.
        expect(function () use ($entry): void {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->acme['tenant']->getKey(),
                'company_id' => $this->company->getKey(),
                'journal_entry_id' => $entry->getKey(),
                'account_id' => $this->rent->getKey(),
                'line_number' => 99,
                'debit' => '500.0000',
                'credit' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        })->toThrow(QueryException::class);
    });

    it('permits a balanced pair of raw inserts in one transaction', function (): void {
        // The deferral is what makes this possible: an immediate check would fail on the first line
        // of every entry, including this legitimate one.
        $entry = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));
        $posted = $this->posting->post($entry, $this->owner);

        foreach ([['debit' => '500.0000', 'credit' => '0.0000'], ['debit' => '0.0000', 'credit' => '500.0000']] as $index => $amounts) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->acme['tenant']->getKey(),
                'company_id' => $this->company->getKey(),
                'journal_entry_id' => $posted->getKey(),
                'account_id' => $this->rent->getKey(),
                'line_number' => 50 + $index,
                ...$amounts,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The deferral is what makes this legal: after the first insert the entry is unbalanced, and
        // an immediate check would reject a pair that is perfectly correct once complete. Forcing the
        // check only now proves the deferral is doing that work rather than the trigger being absent.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        expect(JournalLine::query()->where('journal_entry_id', $posted->getKey())->count())->toBe(4);
    });

    it('refuses a line that is neither a debit nor a credit', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // Zero on both sides survives every balance check because zero equals zero, and then shows on
        // the account ledger as a movement of nothing.
        expect(fn () => DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'journal_entry_id' => $entry->getKey(),
            'account_id' => $this->rent->getKey(),
            'line_number' => 98,
            'debit' => '0.0000',
            'credit' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a negative amount at the database', function (): void {
        expect(fn () => DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->acme['tenant']->getKey(),
            'company_id' => $this->company->getKey(),
            'journal_entry_id' => (string) Str::uuid7(),
            'account_id' => $this->rent->getKey(),
            'line_number' => 1,
            'debit' => '-100.0000',
            'credit' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('immutability at the database', function (): void {
    beforeEach(function (): void {
        $this->posted = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );
    });

    it('refuses to edit a posted entry through the service', function (): void {
        expect(catchPlatformException(
            fn () => $this->journals->updateDraft($this->posted, entryData($this->rent->getKey(), $this->cash->getKey())),
        )->problemCode())->toBe('posted-entry-immutable');
    });

    it('refuses to change a posted entry’s description by raw SQL', function (): void {
        expect(fn () => DB::table('journal_entries')
            ->where('id', $this->posted->getKey())
            ->update(['description' => 'Rewritten history']))
            ->toThrow(QueryException::class);
    });

    it('refuses to change a posted entry’s date by raw SQL', function (): void {
        // Moving a posted entry between periods would take its amounts with it, changing two months'
        // reported figures at once.
        expect(fn () => DB::table('journal_entries')
            ->where('id', $this->posted->getKey())
            ->update(['entry_date' => '2026-07-01']))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a posted entry by raw SQL', function (): void {
        expect(fn () => DB::table('journal_entries')->where('id', $this->posted->getKey())->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses to change a posted entry’s lines by raw SQL', function (): void {
        // The more valuable target: protecting the header while leaving the amounts editable would
        // secure nothing.
        expect(fn () => DB::table('journal_lines')
            ->where('journal_entry_id', $this->posted->getKey())
            ->update(['debit' => '999999.0000']))
            ->toThrow(QueryException::class);
    });

    it('refuses to delete a posted entry’s lines by raw SQL', function (): void {
        expect(fn () => DB::table('journal_lines')->where('journal_entry_id', $this->posted->getKey())->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses to revert a posted entry to draft', function (): void {
        // The one transition the trigger permits is posted → reversed. Anything else, including
        // "un-posting", is refused.
        expect(fn () => DB::table('journal_entries')
            ->where('id', $this->posted->getKey())
            ->update(['status' => 'draft']))
            ->toThrow(QueryException::class);
    });

    it('permits editing and deleting a draft', function (): void {
        $draft = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        // The trigger is conditioned on the entry not being a draft, so nothing here is impeded.
        $this->journals->updateDraft($draft, entryData($this->rent->getKey(), $this->cash->getKey()));
        $this->journals->deleteDraft($draft);

        expect(JournalEntry::query()->whereKey($draft->getKey())->exists())->toBeFalse();
    });
});

describe('reversal', function (): void {
    beforeEach(function (): void {
        $this->posted = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey(), '750.00'),
            $this->owner,
        );
    });

    it('writes a mirror entry rather than editing the original', function (): void {
        $reversal = $this->posting->reverse($this->posted, 'Wrong account', actor: $this->owner);

        $original = $this->posted->fresh();

        expect($reversal->getKey())->not->toBe($this->posted->getKey())
            ->and($original?->status)->toBe(JournalEntryStatus::Reversed)
            ->and($original?->reversed_by_entry_id)->toBe($reversal->getKey())
            ->and($reversal->reverses_entry_id)->toBe($this->posted->getKey());
    });

    it('swaps every side', function (): void {
        $reversal = $this->posting->reverse($this->posted, 'Wrong account', actor: $this->owner);

        $originalDebit = $this->posted->lines->firstWhere('account_id', $this->cash->getKey());
        $reversalLine = $reversal->lines->firstWhere('account_id', $this->cash->getKey());

        expect($originalDebit?->isDebit())->toBeTrue()
            ->and($reversalLine?->isDebit())->toBeFalse()
            ->and($reversalLine?->credit)->toBe('750.0000');
    });

    it('leaves the two entries cancelling exactly', function (): void {
        $reversal = $this->posting->reverse($this->posted, 'Wrong account', actor: $this->owner);

        // Amounts are copied rather than recomputed, so the reversal is exact by construction — a
        // recomputation could round differently and leave a residue.
        $net = JournalLine::query()
            ->whereIn('journal_entry_id', [$this->posted->getKey(), $reversal->getKey()])
            ->get()
            ->reduce(
                static fn (int $carry, JournalLine $line): int => $carry + $line->debit_minor_units - $line->credit_minor_units,
                0,
            );

        expect($net)->toBe(0);
    });

    it('records the reason', function (): void {
        $this->posting->reverse($this->posted, 'Posted to the wrong period', actor: $this->owner);

        expect($this->posted->fresh()?->reversal_reason)->toBe('Posted to the wrong period');
    });

    it('dates the reversal today rather than backdating it into the original’s period', function (): void {
        $reversal = $this->posting->reverse($this->posted, 'Wrong account', actor: $this->owner);

        // Backdating a correction into a period that may be closed is exactly what closing prevents.
        expect($reversal->entry_date->toDateString())->toBe(CarbonImmutable::now()->toDateString());
    });

    it('refuses to reverse the same entry twice', function (): void {
        $this->posting->reverse($this->posted, 'First', actor: $this->owner);

        expect(catchPlatformException(
            fn () => $this->posting->reverse($this->posted->fresh(), 'Second', actor: $this->owner),
        )->problemCode())->toBe('entry-already-reversed');
    });

    it('refuses to reverse a draft', function (): void {
        $draft = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        expect(catchPlatformException(fn () => $this->posting->reverse($draft, 'Nope'))->problemCode())
            ->toBe('cannot-reverse-draft');
    });

    it('leaves both entries in the ledger', function (): void {
        $reversal = $this->posting->reverse($this->posted, 'Wrong account', actor: $this->owner);

        // Both remain and cancel. Removing either from the balance calculation would leave the trial
        // balance out by the entry's amount.
        expect(JournalEntry::query()->whereKey($this->posted->getKey())->exists())->toBeTrue()
            ->and(JournalEntry::query()->whereKey($reversal->getKey())->exists())->toBeTrue();
    });
});

describe('document numbering', function (): void {
    it('numbers sequentially within a period', function (): void {
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->posting->postNew(
                $this->company,
                entryData($this->cash->getKey(), $this->sales->getKey()),
                $this->owner,
            )->number;
        }

        expect($numbers)->toBe(['JV-2026-06-0001', 'JV-2026-06-0002', 'JV-2026-06-0003']);
    });

    it('restarts numbering in a new period', function (): void {
        $june = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey(), date: '2026-06-15'),
            $this->owner,
        );

        $july = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey(), date: '2026-07-15'),
            $this->owner,
        );

        // An accountant reading a number should be able to tell when it was issued. A counter that
        // never resets reaches six digits and stops being readable.
        expect($june->number)->toBe('JV-2026-06-0001')
            ->and($july->number)->toBe('JV-2026-07-0001');
    });

    it('numbers each company independently', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::parse('2026-06-15'));

        $this->posting->postNew($this->company, entryData($this->cash->getKey(), $this->sales->getKey()), $this->owner);

        $secondCash = Account::query()->forCompany($second->getKey())->where('code', '1110')->firstOrFail();
        $secondSales = Account::query()->forCompany($second->getKey())->where('code', '4100')->firstOrFail();

        $entry = $this->posting->postNew(
            $second,
            entryData($secondCash->getKey(), $secondSales->getKey()),
            $this->owner,
        );

        expect($entry->number)->toBe('JV-2026-06-0001');
    });

    it('returns a reserved number to the sequence when its transaction rolls back', function (): void {
        // The property gaplessness actually rests on, asserted directly rather than through the
        // guard that protects it.
        //
        // The guard itself — `DocumentNumberService` refusing to issue outside a transaction —
        // cannot be exercised here: `RefreshDatabase` holds an open transaction for the whole test,
        // so `DB::transactionLevel()` is never zero and the guard never fires. It is production code
        // for a production condition. What *can* be proven is the behaviour it exists to guarantee.
        try {
            DB::transaction(function (): void {
                $this->posting->postNew(
                    $this->company,
                    entryData($this->cash->getKey(), $this->sales->getKey()),
                    $this->owner,
                );

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $next = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // The rolled-back entry consumed nothing. A PostgreSQL SEQUENCE would have handed this
        // entry 0002 and left 0001 permanently unaccounted for.
        expect($next->number)->toBe('JV-2026-06-0001');
    });

    it('refuses two entries with the same number at the database', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        $draft = $this->journals->draft($this->company, entryData($this->cash->getKey(), $this->sales->getKey()));

        // The unique index is what makes a race produce a conflict rather than two documents sharing
        // an identifier.
        expect(fn () => DB::table('journal_entries')
            ->where('id', $draft->getKey())
            ->update(['number' => $entry->number, 'status' => 'posted', 'posted_at' => now()]))
            ->toThrow(QueryException::class);
    });
});

describe('foreign currency, before the FX phase', function (): void {
    it('writes lines with no transaction currency', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // NULL is meaningful: the line is in the company's base currency at rate 1. No redundant rows
        // of LKR / 1.0000.
        expect($entry->lines->first()?->transaction_currency_code)->toBeNull()
            ->and($entry->lines->first()?->isForeignCurrency())->toBeFalse();
    });

    it('refuses a populated transaction currency until the FX phase', function (): void {
        $entry = $this->posting->postNew(
            $this->company,
            entryData($this->cash->getKey(), $this->sales->getKey()),
            $this->owner,
        );

        // `journal_lines_single_currency_until_fx_phase`. This is what makes "base currency only"
        // true rather than intended — the FX phase drops exactly this constraint and nothing else.
        expect(fn () => DB::table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())
            ->limit(1)
            ->update([
                'transaction_currency_code' => 'USD',
                'transaction_amount' => '10.0000',
                'exchange_rate' => '300.0000000000',
            ]))->toThrow(QueryException::class);
    });

    it('keeps the all-or-nothing shape rule independent of the phase constraint', function (): void {
        // The permanent rule: the three columns move together. Asserted separately from the
        // phase-scoped one so that dropping the latter in the FX phase does not quietly remove this.
        $constraints = DB::select(<<<'SQL'
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'journal_lines'::regclass
              AND conname IN ('journal_lines_currency_columns_check', 'journal_lines_single_currency_until_fx_phase')
        SQL);

        expect(collect($constraints)->pluck('conname')->sort()->values()->all())
            ->toBe(['journal_lines_currency_columns_check', 'journal_lines_single_currency_until_fx_phase']);
    });
});

describe('tenant isolation', function (): void {
    it('does not show another workspace’s entries', function (): void {
        $globex = $this->createWorkspace('globex');

        $this->posting->postNew($this->company, entryData($this->cash->getKey(), $this->sales->getKey()), $this->owner);

        $seenByGlobex = app(TenantContext::class)->runFor(
            $globex['tenant'],
            static fn (): int => JournalEntry::query()->count(),
        );

        expect($seenByGlobex)->toBe(0);
    });
});
