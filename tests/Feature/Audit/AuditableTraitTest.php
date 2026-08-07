<?php

declare(strict_types=1);

use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Support\Fixtures\AuditedRecord;

/**
 * The `Auditable` trait and its observer.
 *
 * Until now nothing applied the trait. No Phase 1 model needs it — this phase's security-relevant
 * changes are captured by the eleven domain-event listeners in AuditServiceProvider instead, and the
 * trait exists for the invoices, journals and payments that arrive with Accounting. The consequence
 * was that the trait shipped completely unexercised, and PHPStan does not even analyse a trait that
 * no class uses, so it was not type-checked either.
 *
 * `AuditedRecord` stands in for those future documents. It is a real model on a real table, so the
 * observer registration, the column filtering and the tagging are executed rather than assumed.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');

    // The morph map is enforced, so an unregistered class throws rather than storing a class name.
    // Added here rather than in the application's map: the fixture must not appear in production.
    Relation::morphMap([AuditedRecord::MORPH_ALIAS => AuditedRecord::class]);

    AuditedRecord::createTable();

    $this->withinTenant($this->acme['tenant']);

    $this->actingAs($this->acme['owner']);
});

/**
 * @return array<string, mixed>
 */
function latestEntryFor(AuditedRecord $record, AuditEvent $event): array
{
    /** @var AuditLog $entry */
    $entry = AuditLog::query()
        ->where('auditable_type', AuditedRecord::MORPH_ALIAS)
        ->where('auditable_id', $record->getKey())
        ->where('event', $event->value)
        ->latest('sequence')
        ->firstOrFail();

    return $entry->getAttributes();
}

describe('lifecycle capture', function (): void {
    it('records a creation', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $entry = latestEntryFor($record, AuditEvent::Created);

        expect($entry['auditable_type'])->toBe(AuditedRecord::MORPH_ALIAS)
            ->and($entry['auditable_id'])->toBe($record->getKey())
            ->and($entry['tenant_id'])->toBe($this->acme['tenant']->getKey());
    });

    it('records an update with only what changed', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $record->note = 'Corrected';
        $record->save();

        $entry = latestEntryFor($record, AuditEvent::Updated);

        $old = json_decode((string) $entry['old_values'], true);
        $new = json_decode((string) $entry['new_values'], true);

        // Only the changed key, on both sides. Storing every original attribute would double the
        // row size to record what did not change.
        expect($new)->toBe(['note' => 'Corrected'])
            ->and($old)->toBe(['note' => 'Opening']);
    });

    it('records a soft delete as a deletion', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $record->delete();

        // Mechanically an update to `deleted_at`, but it reads as a deletion to anyone reviewing the
        // trail, so it is recorded as one.
        expect(latestEntryFor($record, AuditEvent::Deleted))->not->toBeEmpty();
    });

    it('records a restore', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);
        $record->delete();
        $record->restore();

        expect(latestEntryFor($record, AuditEvent::Restored))->not->toBeEmpty();
    });

    it('writes the entry in the same transaction as the change', function (): void {
        $record = null;

        try {
            DB::transaction(function () use (&$record): void {
                $record = AuditedRecord::query()->create([
                    'reference' => 'REF-ROLLED-BACK', 'note' => 'Doomed', 'amount' => '1.00',
                ]);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        // The audit entry must not survive a rolled-back change: a trail describing a document that
        // does not exist is worse than no trail, because it cannot be reconciled with the ledger.
        expect(AuditLog::query()->where('auditable_type', AuditedRecord::MORPH_ALIAS)->count())->toBe(0)
            ->and(AuditedRecord::query()->where('reference', 'REF-ROLLED-BACK')->exists())->toBeFalse();
    });
});

describe('column filtering', function (): void {
    it('records only the columns `auditOnly` names', function (): void {
        $record = AuditedRecord::query()->create([
            'reference' => 'REF-1',
            'note' => 'Opening',
            'amount' => '10.00',
            'internal_state' => 'queued',
        ]);

        $new = json_decode((string) latestEntryFor($record, AuditEvent::Created)['new_values'], true);

        // `internal_state` is deliberately outside the list. An audit trail an auditor cannot read
        // because two thirds of every entry is internal bookkeeping is not a usable record.
        expect($new)->toHaveKeys(['reference', 'note', 'amount'])
            ->and($new)->not->toHaveKey('internal_state')
            ->and($new)->not->toHaveKey('id')
            ->and($new)->not->toHaveKey('created_at');
    });

    it('writes no entry at all when only excluded columns changed', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $before = AuditLog::query()->where('auditable_type', AuditedRecord::MORPH_ALIAS)->count();

        $record->internal_state = 'processed';
        $record->save();

        // Distinct from "records an empty update": the observer returns before calling the recorder,
        // so there is no round trip either. A trail where most rows are internal state changes
        // buries the invoice approval an auditor is actually looking for.
        expect(AuditLog::query()->where('auditable_type', AuditedRecord::MORPH_ALIAS)->count())->toBe($before);
    });

    it('records a change that touches both audited and excluded columns', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $record->fill(['note' => 'Revised', 'internal_state' => 'processed']);
        $record->save();

        $new = json_decode((string) latestEntryFor($record, AuditEvent::Updated)['new_values'], true);

        expect($new)->toBe(['note' => 'Revised']);
    });
});

describe('tagging', function (): void {
    it('stores the tags the model declares', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $tags = json_decode((string) latestEntryFor($record, AuditEvent::Created)['tags'], true);

        // Indexed with `jsonb_path_ops`, so "show me everything tagged payroll" over seven years
        // does not become a table scan.
        expect($tags)->toBe(['fixture', 'ledger']);
    });
});

describe('attribution', function (): void {
    it('attributes the change to the acting user', function (): void {
        $record = AuditedRecord::query()->create(['reference' => 'REF-1', 'note' => 'Opening', 'amount' => '10.00']);

        $entry = latestEntryFor($record, AuditEvent::Created);

        expect($entry['actor_id'])->toBe($this->acme['owner']->getKey())
            ->and($entry['actor_label'])->not->toBeEmpty();
    });

    it('records an unattributed change without failing', function (): void {
        // A console command or a queued job has no authenticated user. Refusing to record, or
        // throwing, would mean the scheduled paths are the ones with no trail.
        app('auth')->forgetGuards();
        $this->app['auth']->guard('web')->logout();

        $record = AuditedRecord::query()->create(['reference' => 'REF-2', 'note' => 'System', 'amount' => '5.00']);

        expect(latestEntryFor($record, AuditEvent::Created)['actor_id'])->toBeNull();
    });
});

describe('isolation', function (): void {
    it('scopes entries to the workspace the change happened in', function (): void {
        $globex = $this->createWorkspace('globex');

        AuditedRecord::query()->create(['reference' => 'ACME-1', 'note' => 'Acme', 'amount' => '1.00']);

        app(TenantContext::class)->runFor(
            $globex['tenant'],
            fn () => AuditedRecord::query()->create(['reference' => 'GLOBEX-1', 'note' => 'Globex', 'amount' => '2.00']),
        );

        // Back inside acme. One workspace being able to read another's audit trail would expose the
        // one table that describes everything the other has ever done.
        $visible = AuditLog::query()->where('auditable_type', AuditedRecord::MORPH_ALIAS)->get();

        expect($visible)->toHaveCount(1)
            ->and($visible->first()?->tenant_id)->toBe($this->acme['tenant']->getKey());
    });
});
