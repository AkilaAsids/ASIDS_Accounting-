<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Application\Services;

use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\DB;

/**
 * Links unsealed audit entries into the tamper-evident hash chain, and verifies it.
 *
 * Runs out of band (every five minutes) so the write path stays lock-free — see the migration
 * header for why that trade is made. Sealing is the only operation permitted to UPDATE
 * `audit_logs`, and the database trigger constrains it to filling in the three chain columns of
 * a not-yet-sealed row while every meaningful column stays byte-identical. Even holding UPDATE
 * rights, the sealer cannot rewrite history.
 */
final readonly class AuditChainSealer
{
    /**
     * Seal every unsealed entry for one tenant.
     *
     * @return array{sealed: int, from_sequence: int|null, to_sequence: int|null}
     */
    public function seal(?string $tenantId, int $batchSize = 5000): array
    {
        return RowLevelSecurity::bypass(fn (): array => DB::transaction(function () use ($tenantId, $batchSize): array {
            // A transaction-scoped advisory lock, so two schedulers — or a manual run racing the
            // scheduled one — cannot both chain the same tail and produce two different valid
            // hashes for the same row. Released automatically on commit or rollback.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['asids:audit-seal:'.($tenantId ?? 'platform')]);

            // Permits the UPDATE the trigger otherwise refuses, for this transaction only.
            DB::statement("SELECT set_config('asids.audit_seal', 'on', true)");

            $previousHash = $this->latestSealedHash($tenantId);

            /** @var list<AuditLog> $pending */
            $pending = AuditLog::query()
                ->withoutGlobalScopes()
                ->when($tenantId === null,
                    static fn ($query) => $query->whereNull('tenant_id'),
                    static fn ($query) => $query->where('tenant_id', $tenantId),
                )
                ->unsealed()
                ->orderBy('sequence')
                ->limit($batchSize)
                ->get()
                ->all();

            if ($pending === []) {
                return ['sealed' => 0, 'from_sequence' => null, 'to_sequence' => null];
            }

            $sealedAt = now();

            foreach ($pending as $entry) {
                $hash = $entry->computeHash($previousHash);

                // A bare UPDATE rather than a model save: the model is guarded against writes,
                // and the trigger compares every other column for equality, so touching anything
                // else here would be refused.
                DB::table('audit_logs')
                    ->where('id', $entry->getKey())
                    ->whereNull('sealed_at')
                    ->update([
                        'previous_hash' => $previousHash,
                        'hash' => $hash,
                        'sealed_at' => $sealedAt,
                    ]);

                $previousHash = $hash;
            }

            return [
                'sealed' => count($pending),
                'from_sequence' => $pending[0]->sequence,
                'to_sequence' => $pending[count($pending) - 1]->sequence,
            ];
        }));
    }

    /**
     * Walk a tenant's sealed chain and report the first break.
     *
     * Two failure modes are distinguished, because they mean different things: a **hash
     * mismatch** means a row's contents were altered, while a **broken link** means a row was
     * removed. An auditor needs to know which.
     *
     * @return array{verified: int, intact: bool, failure: array<string, mixed>|null}
     */
    public function verify(?string $tenantId, ?int $fromSequence = null): array
    {
        return RowLevelSecurity::bypass(function () use ($tenantId, $fromSequence): array {
            $expectedPrevious = $fromSequence === null
                ? null
                : $this->hashBefore($tenantId, $fromSequence);

            $verified = 0;
            $failure = null;

            AuditLog::query()
                ->withoutGlobalScopes()
                ->when($tenantId === null,
                    static fn ($query) => $query->whereNull('tenant_id'),
                    static fn ($query) => $query->where('tenant_id', $tenantId),
                )
                ->whereNotNull('sealed_at')
                ->when($fromSequence !== null, static fn ($query) => $query->where('sequence', '>=', $fromSequence))
                ->orderBy('sequence')
                // Chunked so verifying a seven-year trail does not load it into memory.
                ->chunk(1000, function ($entries) use (&$expectedPrevious, &$verified, &$failure): bool {
                    foreach ($entries as $entry) {
                        /** @var AuditLog $entry */
                        if ($entry->previous_hash !== $expectedPrevious) {
                            $failure = [
                                'kind' => 'broken_link',
                                'detail' => 'This entry does not follow the previous one. An entry has been removed.',
                                'sequence' => $entry->sequence,
                                'id' => $entry->getKey(),
                                'expected_previous_hash' => $expectedPrevious,
                                'stored_previous_hash' => $entry->previous_hash,
                            ];

                            return false;
                        }

                        $recomputed = $entry->computeHash($entry->previous_hash);

                        if (! hash_equals((string) $entry->hash, $recomputed)) {
                            $failure = [
                                'kind' => 'hash_mismatch',
                                'detail' => 'This entry\'s contents no longer match its hash. The row has been altered.',
                                'sequence' => $entry->sequence,
                                'id' => $entry->getKey(),
                                'stored_hash' => $entry->hash,
                                'recomputed_hash' => $recomputed,
                            ];

                            return false;
                        }

                        $expectedPrevious = $entry->hash;
                        $verified++;
                    }

                    return true;
                });

            return [
                'verified' => $verified,
                'intact' => $failure === null,
                'failure' => $failure,
            ];
        });
    }

    private function latestSealedHash(?string $tenantId): ?string
    {
        /** @var string|null $hash */
        $hash = AuditLog::query()
            ->withoutGlobalScopes()
            ->when($tenantId === null,
                static fn ($query) => $query->whereNull('tenant_id'),
                static fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->whereNotNull('sealed_at')
            ->orderByDesc('sequence')
            ->value('hash');

        return $hash;
    }

    private function hashBefore(?string $tenantId, int $sequence): ?string
    {
        /** @var string|null $hash */
        $hash = AuditLog::query()
            ->withoutGlobalScopes()
            ->when($tenantId === null,
                static fn ($query) => $query->whereNull('tenant_id'),
                static fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->whereNotNull('sealed_at')
            ->where('sequence', '<', $sequence)
            ->orderByDesc('sequence')
            ->value('hash');

        return $hash;
    }
}
