<?php

declare(strict_types=1);

use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The audit HTTP surface and the scheduled commands that maintain it.
 *
 * AuditChainIntegrityTest covers the chain itself — sealing, hash mismatch, broken links. This covers
 * the operator's view of it: the endpoints an auditor reads, and the four commands that run on a
 * schedule and are therefore the code least likely to be noticed when it breaks.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');
});

function asAuditor(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

/**
 * Produces audit entries the way the application does — by performing an audited action.
 */
function generateAuditActivity(): void
{
    test()->actingAs(RowLevelSecurity::bypass(static fn (): ?User => test()->owner->fresh()))
        ->withHeader('X-Tenant', 'acme')
        ->json('POST', '/api/v1/users/'.test()->accountant->getKey().'/suspend', ['reason' => 'Audit fixture'])
        ->assertSuccessful();
}

describe('reading the audit trail', function (): void {
    it('returns entries newest first', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'GET', '/api/v1/audit');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();

        $sequences = collect($response->json('data'))->pluck('sequence')->all();

        // Ordered by sequence, not `created_at`: two entries can share a timestamp to the
        // microsecond, and only the sequence gives a total order that matches the chain.
        expect($sequences)->toBe(collect($sequences)->sortDesc()->values()->all());
    });

    it('filters by event', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'GET', '/api/v1/audit?filter[event]=user_suspended');

        $events = collect($response->json('data'))->pluck('event')->unique()->all();

        expect($events)->toBeIn([[], ['user_suspended']]);
    });

    it('refuses an unsupported sort rather than ignoring it', function (): void {
        $response = asAuditor($this->owner, 'GET', '/api/v1/audit?sort=actor_label');

        // The allow-list is a security boundary here: `?sort=` reaches an ORDER BY.
        expect($response)->toBeProblem('unsupported-sort');
    });

    it('returns the whole history of one record', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'GET', "/api/v1/audit/records/user/{$this->accountant->getKey()}");

        expect($response)->toBeEnvelope()
            ->and($response->json('meta.record.type'))->toBe('user');
    });

    it('never returns another workspace’s entries', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'GET', '/api/v1/audit');

        $tenants = RowLevelSecurity::bypass(fn (): array => AuditLog::query()
            ->withoutGlobalScopes()
            ->whereIn('id', collect($response->json('data'))->pluck('id')->all())
            ->pluck('tenant_id')
            ->unique()
            ->all());

        // The audit trail is the one table that describes everything a customer has ever done.
        expect($tenants)->toBe([$this->acme['tenant']->getKey()]);
    });

    it('refuses a caller without the audit permission', function (): void {
        expect(asAuditor($this->viewer, 'GET', '/api/v1/audit')->getStatusCode())->toBe(403);
    });

    it('redacts credentials in the entries it returns', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'GET', '/api/v1/audit');

        expect($response)->toNotExposeFields('password', 'two_factor_secret', 'remember_token');
    });
});

describe('the activity feed', function (): void {
    it('returns recent activity', function (): void {
        generateAuditActivity();

        expect(asAuditor($this->owner, 'GET', '/api/v1/activity'))->toBeEnvelope();
    });

    it('refuses a caller without the permission', function (): void {
        expect(asAuditor($this->viewer, 'GET', '/api/v1/activity')->getStatusCode())->toBe(403);
    });
});

describe('chain verification over HTTP', function (): void {
    it('reports an unsealed trail as intact', function (): void {
        generateAuditActivity();

        $response = asAuditor($this->owner, 'POST', '/api/v1/audit/verify');

        expect($response)->toBeEnvelope()
            ->and($response->json('data.intact'))->toBeTrue()
            // Entries are written unsealed and chained by the nightly command, so the count of
            // unsealed rows is the operator's signal that sealing is running.
            ->and($response->json('data.unsealed_entries'))->toBeGreaterThan(0);
    });

    it('reports a tampered trail as broken', function (): void {
        generateAuditActivity();

        Artisan::call('asids:audit-seal');

        // Simulates an attacker with database access editing history. The append-only trigger blocks
        // this from the application, so the tamper is applied with the trigger disabled — which is
        // exactly what someone holding that much privilege would do, and the case the hash chain
        // exists to catch.
        RowLevelSecurity::bypass(function (): void {
            $entry = AuditLog::query()->withoutGlobalScopes()->whereNotNull('sealed_at')->firstOrFail();

            DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_guard');
            DB::table('audit_logs')->where('id', $entry->getKey())
                ->update(['new_values' => json_encode(['tampered' => true])]);
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_guard');
        });

        $response = asAuditor($this->owner, 'POST', '/api/v1/audit/verify');

        expect($response->json('data.intact'))->toBeFalse()
            ->and($response->json('data.failure'))->not->toBeNull();
    });

    it('refuses verification to a caller without the permission', function (): void {
        expect(asAuditor($this->viewer, 'POST', '/api/v1/audit/verify')->getStatusCode())->toBe(403);
    });
});

describe('the scheduled commands', function (): void {
    it('seals unsealed entries', function (): void {
        generateAuditActivity();

        $before = RowLevelSecurity::bypass(
            fn (): int => AuditLog::query()->withoutGlobalScopes()->whereNull('sealed_at')->count(),
        );

        expect($before)->toBeGreaterThan(0);

        expect(Artisan::call('asids:audit-seal'))->toBe(0);

        $after = RowLevelSecurity::bypass(
            fn (): int => AuditLog::query()->withoutGlobalScopes()->whereNull('sealed_at')->count(),
        );

        expect($after)->toBe(0);
    });

    it('is idempotent — a second seal run chains nothing', function (): void {
        generateAuditActivity();

        Artisan::call('asids:audit-seal');

        $hashes = RowLevelSecurity::bypass(fn (): array => AuditLog::query()
            ->withoutGlobalScopes()
            ->orderBy('sequence')
            ->pluck('hash')
            ->all());

        Artisan::call('asids:audit-seal');

        expect(RowLevelSecurity::bypass(fn (): array => AuditLog::query()
            ->withoutGlobalScopes()
            ->orderBy('sequence')
            ->pluck('hash')
            ->all()))->toBe($hashes);
    });

    it('verifies the chain and exits zero when intact', function (): void {
        generateAuditActivity();
        Artisan::call('asids:audit-seal');

        // A non-zero exit here means someone with database access rewrote history, and the schedule
        // e-mails the output. It has to be exact.
        expect(Artisan::call('asids:audit-verify'))->toBe(0);
    });

    it('reports without deleting unless told to', function (): void {
        generateAuditActivity();

        $before = RowLevelSecurity::bypass(
            fn (): int => AuditLog::query()->withoutGlobalScopes()->count(),
        );

        // No `--confirm`: a retention sweep that deletes by default is one careless invocation away
        // from destroying the record it exists to protect.
        expect(Artisan::call('asids:audit-prune'))->toBe(0);

        expect(RowLevelSecurity::bypass(fn (): int => AuditLog::query()->withoutGlobalScopes()->count()))
            ->toBe($before);
    });

    it('keeps entries inside the retention window even when confirmed', function (): void {
        generateAuditActivity();

        config(['asids.audit.retention_days' => 2555]);

        $before = RowLevelSecurity::bypass(
            fn (): int => AuditLog::query()->withoutGlobalScopes()->count(),
        );

        expect(Artisan::call('asids:audit-prune', ['--confirm' => true]))->toBe(0);

        // Seven years by default, which is the Sri Lankan statutory retention period. Today's entries
        // are nowhere near it.
        expect(RowLevelSecurity::bypass(fn (): int => AuditLog::query()->withoutGlobalScopes()->count()))
            ->toBe($before);
    });

    it('synchronises the permission catalogue', function (): void {
        expect(Artisan::call('asids:sync-permissions'))->toBe(0);

        // Idempotent: it runs on every release, and a second run must be a no-op rather than
        // duplicating rows or revoking anything.
        expect(Artisan::call('asids:sync-permissions'))->toBe(0);
    });

    it('revokes tokens whose expiry has passed', function (): void {
        $token = RowLevelSecurity::bypass(function (): PersonalAccessToken {
            $token = new PersonalAccessToken;

            $token->forceFill([
                'tenant_id' => $this->acme['tenant']->getKey(),
                'tokenable_type' => 'user',
                'tokenable_id' => $this->owner->getKey(),
                'name' => 'Long expired',
                'token' => hash('sha256', 'expired-token-plaintext'),
                'abilities' => ['identity.users.view'],
                'expires_at' => now()->subDay(),
            ])->save();

            return $token;
        });

        expect(Artisan::call('asids:revoke-expired-tokens'))->toBe(0);

        // Marked revoked rather than deleted: "this integration's token expired on the 3rd" is a
        // question an operator asks, and a deleted row cannot answer it.
        expect(RowLevelSecurity::bypass(fn (): ?PersonalAccessToken => $token->fresh())?->revoked_at)
            ->not->toBeNull();
    });

    it('leaves an unexpired token alone', function (): void {
        $token = RowLevelSecurity::bypass(function (): PersonalAccessToken {
            $token = new PersonalAccessToken;

            $token->forceFill([
                'tenant_id' => $this->acme['tenant']->getKey(),
                'tokenable_type' => 'user',
                'tokenable_id' => $this->owner->getKey(),
                'name' => 'Still valid',
                'token' => hash('sha256', 'valid-token-plaintext'),
                'abilities' => ['identity.users.view'],
                'expires_at' => now()->addYear(),
            ])->save();

            return $token;
        });

        Artisan::call('asids:revoke-expired-tokens');

        expect(RowLevelSecurity::bypass(fn (): ?PersonalAccessToken => $token->fresh())?->revoked_at)
            ->toBeNull();
    });

    it('passes the deployment security check', function (): void {
        // Gates releases. It fails hard when row level security is not actually in force, which is
        // the condition that presents as "all my data has vanished" rather than as an error.
        expect(Artisan::call('asids:security-check'))->toBe(0);
    });
});
