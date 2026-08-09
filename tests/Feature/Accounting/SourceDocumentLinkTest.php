<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Accounting\Domain\ValueObjects\SourceDocument;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Fixtures\SourceRecord;
use Tests\Support\Fixtures\UnmappedRecord;

/**
 * The link from a ledger entry back to the document that caused it.
 *
 * Milestone 1 of Phase 3, and the reason it comes first: sales invoicing needs to trace an entry to
 * its invoice, and needs the database to refuse a second posting of the same invoice. Both are
 * properties of `journal_entries`, so they are established before any document exists to use them.
 *
 * `SourceRecord` stands in for the invoice. Using a fixture rather than a real document keeps this
 * suite testing the mechanism rather than any particular document's schema — the same reasoning that
 * gave `AuditedRecord` to the audit trait, and it means these tests do not need rewriting when
 * invoices land in Milestone 4.
 *
 * The property under test throughout is that the *database* enforces this. A service check on the
 * invoice's status is racy — two concurrent requests both read `draft` and both proceed — so every
 * assertion here that matters goes through raw SQL or a direct insert, bypassing the service that
 * would otherwise be the thing being tested.
 */
beforeEach(function (): void {
    Relation::morphMap([SourceRecord::MORPH_ALIAS => SourceRecord::class]);
    SourceRecord::createTable();

    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->cash = Account::query()->forCompany($this->company->getKey())->where('code', '1110')->firstOrFail();
    $this->sales = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();

    $this->document = SourceRecord::query()->create(['reference' => 'INV-0001']);
});

/**
 * A balanced entry citing the given source.
 */
function entryFor(?SourceDocument $source, string $amount = '1000.00'): JournalEntryData
{
    $currency = test()->company->base_currency_code;

    return new JournalEntryData(
        entryDate: CarbonImmutable::parse('2026-06-15'),
        description: 'Sale',
        lines: [
            new JournalLineData(accountId: test()->cash->getKey(), debit: Money::of($amount, $currency)),
            new JournalLineData(accountId: test()->sales->getKey(), credit: Money::of($amount, $currency)),
        ],
        source: $source,
    );
}

describe('tracing an entry to its document', function (): void {
    it('records the source on a posted entry', function (): void {
        $entry = app(PostingService::class)->postNew(
            $this->company,
            entryFor(SourceDocument::for($this->document)),
        );

        expect($entry->source_type)->toBe(SourceRecord::MORPH_ALIAS)
            ->and($entry->source_id)->toBe($this->document->getKey());
    });

    it('resolves the document back from the entry', function (): void {
        $entry = app(PostingService::class)->postNew(
            $this->company,
            entryFor(SourceDocument::for($this->document)),
        );

        $resolved = $entry->fresh()->source;

        // The point of the whole milestone: an accountant looking at a ledger line can get to the
        // document that caused it.
        expect($resolved)->toBeInstanceOf(SourceRecord::class)
            ->and($resolved->reference)->toBe('INV-0001');
    });

    it('stores a morph alias rather than a class name', function (): void {
        app(PostingService::class)->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        $stored = DB::table('journal_entries')->whereNotNull('source_type')->value('source_type');

        // A fully-qualified class name in this column would be orphaned by any namespace change,
        // and the rows would be unresolvable with no error to point at.
        expect($stored)->toBe('source_record')
            ->and($stored)->not->toContain('\\');
    });

    it('leaves the source null for an entry made directly', function (): void {
        $entry = app(PostingService::class)->postNew($this->company, entryFor(null));

        expect($entry->source_type)->toBeNull()
            ->and($entry->source_id)->toBeNull()
            ->and($entry->sourceDocument())->toBeNull();
    });

    it('finds every entry a document caused, oldest first', function (): void {
        $posting = app(PostingService::class);
        $original = $posting->postNew($this->company, entryFor(SourceDocument::for($this->document)));
        $posting->reverse($original, 'Cancelled', CarbonImmutable::parse('2026-06-20'));

        $history = JournalEntry::query()
            ->forSource(SourceDocument::for($this->document))
            ->get();

        // Both the posting and its mirror, in the order an auditor reads them.
        expect($history)->toHaveCount(2)
            ->and($history->first()->getKey())->toBe($original->getKey());
    });
});

describe('preventing a document from posting twice', function (): void {
    it('refuses a second originating entry for the same document', function (): void {
        $posting = app(PostingService::class);
        $posting->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        // The guard that actually holds under concurrency. A service-level status check would let
        // two simultaneous requests through; the unique index will not.
        expect(fn () => $posting->postNew($this->company, entryFor(SourceDocument::for($this->document))))
            ->toThrow(QueryException::class);
    });

    it('refuses the duplicate even when the service is bypassed entirely', function (): void {
        $entry = app(PostingService::class)->postNew(
            $this->company,
            entryFor(SourceDocument::for($this->document)),
        );

        $row = (array) DB::table('journal_entries')->where('id', $entry->getKey())->first();
        $row['id'] = (string) Str::uuid7();
        $row['number'] = 'JV-2026-06-9999';
        unset($row['created_at'], $row['updated_at']);

        // Inserted straight into the table, so nothing but the index itself can refuse it. This is
        // the assertion that proves the protection is in the database rather than in a service
        // someone could forget to call.
        expect(fn () => DB::table('journal_entries')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]))
            ->toThrow(QueryException::class);
    });

    it('still allows a reversal to cite the document it undoes', function (): void {
        $posting = app(PostingService::class);
        $original = $posting->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        $reversal = $posting->reverse($original, 'Cancelled in error', CarbonImmutable::parse('2026-06-20'));

        // The uniqueness index ignores reversing entries on purpose. Without that, a cancelled
        // invoice's mirror would be the one entry that could not be traced back to it.
        expect($reversal->source_type)->toBe(SourceRecord::MORPH_ALIAS)
            ->and($reversal->source_id)->toBe($this->document->getKey())
            ->and($reversal->reverses_entry_id)->toBe($original->getKey());
    });

    it('lets a different document post its own entry', function (): void {
        $other = SourceRecord::query()->create(['reference' => 'INV-0002']);
        $posting = app(PostingService::class);

        $posting->postNew($this->company, entryFor(SourceDocument::for($this->document)));
        $second = $posting->postNew($this->company, entryFor(SourceDocument::for($other)));

        // Uniqueness is per document, not a global one-entry-per-type rule.
        expect($second->source_id)->toBe($other->getKey());
    });
});

describe('the source columns are all or nothing', function (): void {
    it('refuses a type with no id on a draft', function (): void {
        $draft = app(JournalService::class)->draft($this->company, entryFor(null));

        // A draft is freely editable, so the trigger is not what refuses this — the CHECK is. Worth
        // asserting separately from the insert case: an UPDATE that sets one column and not the
        // other is the likelier shape of a real bug.
        expect(fn () => DB::statement(
            'UPDATE journal_entries SET source_type = ? WHERE id = ?',
            [SourceRecord::MORPH_ALIAS, $draft->getKey()],
        ))->toThrow(QueryException::class);
    });

    it('refuses a half-set pair on insert', function (): void {
        $entry = app(PostingService::class)->postNew($this->company, entryFor(null));

        $row = (array) DB::table('journal_entries')->where('id', $entry->getKey())->first();

        expect(fn () => DB::table('journal_entries')->insert([
            ...$row,
            'id' => (string) Str::uuid7(),
            'number' => 'JV-2026-06-8888',
            'source_type' => 'source_record',
            'source_id' => null,
        ]))->toThrow(QueryException::class);
    });

    it('refuses to build a half-set reference in PHP', function (): void {
        // The same rule as the database CHECK, enforced a layer earlier so the failure names the
        // problem rather than surfacing as a constraint violation.
        expect(fn () => SourceDocument::fromColumns('source_record', null))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses to cite an unsaved document', function (): void {
        expect(fn () => SourceDocument::for(new SourceRecord))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a model that nobody registered in the morph map', function (): void {
        // `UnmappedRecord` is deliberately absent from every morph map, and it saves successfully —
        // so the only thing standing between it and a class name in `source_type` is the round-trip
        // check. `Relation::getMorphAlias()` does not throw for an unmapped class even with the map
        // enforced; it hands back the class name. Without the check this would silently write
        // `Tests\Support\Fixtures\UnmappedRecord` into the column, and the row would stop resolving
        // the day someone renamed the class.
        UnmappedRecord::createTable();
        $orphan = UnmappedRecord::query()->create(['reference' => 'NOPE-0001']);

        expect(fn () => SourceDocument::for($orphan))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('the source of a posted entry cannot be changed', function (): void {
    it('refuses to repoint a posted entry at a different document', function (): void {
        $entry = app(PostingService::class)->postNew(
            $this->company,
            entryFor(SourceDocument::for($this->document)),
        );
        $other = SourceRecord::query()->create(['reference' => 'INV-0002']);

        // The immutability trigger enumerates every column by name, and these two were added to it.
        // A posted entry whose source could be repointed would let a receivable be reattributed to a
        // different invoice after the fact — exactly what an append-only ledger exists to prevent.
        expect(fn () => DB::statement(
            'UPDATE journal_entries SET source_id = ? WHERE id = ?',
            [$other->getKey(), $entry->getKey()],
        ))->toThrow(QueryException::class);
    });

    it('refuses to clear the source of a posted entry', function (): void {
        $entry = app(PostingService::class)->postNew(
            $this->company,
            entryFor(SourceDocument::for($this->document)),
        );

        expect(fn () => DB::statement(
            'UPDATE journal_entries SET source_type = NULL, source_id = NULL WHERE id = ?',
            [$entry->getKey()],
        ))->toThrow(QueryException::class);
    });

    it('still permits the reversal transition the trigger exists to allow', function (): void {
        $posting = app(PostingService::class);
        $original = $posting->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        // Regression guard: extending the trigger must not have broken the one update it permits.
        $reversal = $posting->reverse($original, 'Still works', CarbonImmutable::parse('2026-06-20'));

        expect($original->fresh()->reversed_by_entry_id)->toBe($reversal->getKey());
    });
});

/**
 * Row level security, and only row level security.
 *
 * Every assertion below goes through raw SQL. That is the whole point: `JournalEntry::query()` already
 * carries a `tenant_id` predicate from `BelongsToTenant`'s global scope, so an Eloquent assertion here
 * would pass with the database policies switched off entirely and prove nothing. The application-level
 * scope is worth testing too — it is, in its own group above, without an RLS skip guard implying it
 * shows something it does not.
 */
describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s sourced entries from raw SQL', function (): void {
        app(PostingService::class)->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        $globex = $this->createWorkspace('globex');

        $this->withinTenant($globex['tenant']);

        // The new columns are on a table already under a FORCED policy, so the guarantee should be
        // unchanged — asserted rather than assumed, because a query filtering on source is a new
        // access path and a new access path is where isolation gets missed.
        $visible = DB::table('journal_entries')->whereNotNull('source_id')->count();

        expect($visible)->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('journal_entries'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

});

describe('the application-level tenant scope', function (): void {
    it('excludes another workspace’s entries from a source lookup', function (): void {
        app(PostingService::class)->postNew($this->company, entryFor(SourceDocument::for($this->document)));

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        $found = JournalEntry::query()->forSource(SourceDocument::for($this->document))->count();

        expect($found)->toBe(0);
    });
});
