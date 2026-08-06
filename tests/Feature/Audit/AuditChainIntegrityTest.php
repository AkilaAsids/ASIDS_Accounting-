<?php

declare(strict_types=1);

use Asids\Core\Audit\Application\Services\AuditChainSealer;
use Asids\Core\Audit\Application\Services\AuditRecorder;
use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail's tamper-evidence.
 *
 * Two claims are tested: history cannot be rewritten, and if it somehow is, the chain detects
 * it. The second matters because the first depends on a database trigger — the only PL/pgSQL in
 * the platform, and therefore the piece most worth proving rather than assuming.
 */
beforeEach(function (): void {
    $this->workspace = $this->createWorkspace('acme');
    $this->withinTenant($this->workspace['tenant']);

    $this->recordSome = function (int $count = 3): void {
        for ($i = 0; $i < $count; $i++) {
            app(AuditRecorder::class)->record(
                subject: $this->workspace['company'],
                event: AuditEvent::Updated,
                oldValues: ['name' => "Before {$i}"],
                newValues: ['name' => "After {$i}"],
            );
        }
    };
});

describe('append-only enforcement', function (): void {
    it('refuses to update an entry', function (): void {
        ($this->recordSome)(1);

        $entry = AuditLog::query()->firstOrFail();

        expect(fn () => DB::table('audit_logs')
            ->where('id', $entry->getKey())
            ->update(['event' => 'created']))
            ->toThrow(Illuminate\Database\QueryException::class);
    });

    it('refuses to delete an entry without the pruning declaration', function (): void {
        ($this->recordSome)(1);

        expect(fn () => DB::table('audit_logs')->delete())
            ->toThrow(Illuminate\Database\QueryException::class);
    });

    it('refuses to truncate the table', function (): void {
        ($this->recordSome)(1);

        // TRUNCATE bypasses row triggers entirely, so it needs its own statement trigger. This
        // is the gap a determined operator would find first.
        expect(fn () => DB::statement('TRUNCATE audit_logs'))
            ->toThrow(Illuminate\Database\QueryException::class);
    });

    it('permits deletion when pruning declares itself', function (): void {
        ($this->recordSome)(1);

        RowLevelSecurity::bypass(function (): void {
            DB::transaction(function (): void {
                DB::statement("SELECT set_config('asids.audit_prune', 'on', true)");
                DB::table('audit_logs')->delete();
            });
        });

        expect(AuditLog::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('sealing', function (): void {
    it('leaves new entries unchained on the write path', function (): void {
        ($this->recordSome)(3);

        // Lock-free by design: chaining inline would hold a lock for the whole surrounding
        // business transaction, serialising every audited write in the workspace.
        expect(AuditLog::query()->unsealed()->count())->toBe(3);
    });

    it('chains entries in sequence order', function (): void {
        ($this->recordSome)(3);

        $result = app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());

        expect($result['sealed'])->toBeGreaterThanOrEqual(3);

        $entries = AuditLog::query()->orderBy('sequence')->get();
        $previous = null;

        foreach ($entries as $entry) {
            expect($entry->previous_hash)->toBe($previous)
                ->and($entry->hash)->toBe($entry->computeHash($previous))
                ->and($entry->sealed_at)->not->toBeNull();

            $previous = $entry->hash;
        }
    });

    it('is idempotent — a second run seals nothing', function (): void {
        ($this->recordSome)(2);

        app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());
        $second = app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());

        expect($second['sealed'])->toBe(0);
    });

    it('verifies an untouched chain as intact', function (): void {
        ($this->recordSome)(4);
        app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());

        $result = app(AuditChainSealer::class)->verify((string) $this->workspace['tenant']->getKey());

        expect($result['intact'])->toBeTrue()
            ->and($result['failure'])->toBeNull()
            ->and($result['verified'])->toBeGreaterThan(0);
    });
});

describe('tamper detection', function (): void {
    it('detects an altered entry as a hash mismatch', function (): void {
        ($this->recordSome)(3);
        app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());

        $target = AuditLog::query()->orderBy('sequence')->skip(1)->firstOrFail();

        // Simulates an attacker with database access editing history. The trigger blocks this
        // from the application, so the tamper is applied by disabling the trigger — which is
        // exactly what someone with sufficient privilege would do.
        RowLevelSecurity::bypass(function () use ($target): void {
            DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_guard');
            DB::table('audit_logs')->where('id', $target->getKey())
                ->update(['new_values' => json_encode(['name' => 'Tampered'])]);
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_guard');
        });

        $result = app(AuditChainSealer::class)->verify((string) $this->workspace['tenant']->getKey());

        expect($result['intact'])->toBeFalse()
            ->and($result['failure']['kind'])->toBe('hash_mismatch')
            ->and($result['failure']['sequence'])->toBe($target->sequence);
    });

    it('detects a removed entry as a broken link', function (): void {
        ($this->recordSome)(4);
        app(AuditChainSealer::class)->seal((string) $this->workspace['tenant']->getKey());

        $target = AuditLog::query()->orderBy('sequence')->skip(1)->firstOrFail();

        RowLevelSecurity::bypass(function () use ($target): void {
            DB::transaction(function () use ($target): void {
                DB::statement("SELECT set_config('asids.audit_prune', 'on', true)");
                DB::table('audit_logs')->where('id', $target->getKey())->delete();
            });
        });

        $result = app(AuditChainSealer::class)->verify((string) $this->workspace['tenant']->getKey());

        // A different diagnosis from a mismatch, and the distinction matters to an auditor:
        // altered contents versus a deleted record are different findings.
        expect($result['intact'])->toBeFalse()
            ->and($result['failure']['kind'])->toBe('broken_link');
    });
});

describe('what is recorded', function (): void {
    it('redacts credentials before writing', function (): void {
        app(AuditRecorder::class)->record(
            subject: $this->workspace['owner'],
            event: AuditEvent::Updated,
            oldValues: ['password' => 'old-secret-value'],
            newValues: ['password' => 'new-secret-value', 'first_name' => 'Nimal'],
        );

        $entry = AuditLog::query()->latest('sequence')->firstOrFail();

        // The *fact* of the change is retained; the value is not. "The password changed" is the
        // auditable event, not what it changed to.
        expect($entry->new_values['password'])->toBe('[redacted]')
            ->and($entry->new_values['first_name'])->toBe('Nimal')
            ->and(json_encode($entry->new_values))->not->toContain('new-secret-value');
    });

    it('ignores an update where nothing of substance changed', function (): void {
        $before = AuditLog::query()->count();

        app(AuditRecorder::class)->record(
            subject: $this->workspace['company'],
            event: AuditEvent::Updated,
            oldValues: ['name' => 'Same'],
            newValues: ['name' => 'Same'],
        );

        // An `updated_at` touch would otherwise bury real changes in noise.
        expect(AuditLog::query()->count())->toBe($before);
    });

    it('stores the morph alias rather than a class name', function (): void {
        ($this->recordSome)(1);

        // A namespace refactor must not orphan seven years of history.
        expect(AuditLog::query()->latest('sequence')->value('auditable_type'))->toBe('company');
    });

    it('does not fail the caller when the entry cannot be written', function (): void {
        // The recorder guards its own insert and falls back to the audit log channel. A
        // malformed value must never roll back an otherwise valid business operation.
        expect(fn () => app(AuditRecorder::class)->record(
            subject: $this->workspace['company'],
            event: AuditEvent::Updated,
            newValues: ['deeply' => str_repeat('x', 20000)],
        ))->not->toThrow(Exception::class);
    });
});
