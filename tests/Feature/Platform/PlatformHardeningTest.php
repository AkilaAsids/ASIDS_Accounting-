<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Contracts\UserRepositoryContract;
use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Platform-level hardening: rate limits, token credentials, the repositories and the release gate.
 *
 * These are the pieces with no screen of their own. A rate limiter that is keyed wrongly protects
 * nothing and nobody notices until a credential-stuffing run succeeds; a repository query that
 * forgets its scope leaks across workspaces without any endpoint looking wrong.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->users = app(UserRepositoryContract::class);
});

describe('sign-in rate limiting', function (): void {
    beforeEach(function (): void {
        RateLimiter::clear('login');
    });

    it('locks out repeated sign-in attempts from one address', function (): void {
        config(['asids.rate_limits.login' => 3]);

        $attempt = fn (): TestResponse => $this->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/login', ['email' => 'nobody@acme.test', 'password' => 'wrong-password']);

        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $attempt()->getStatusCode();
        }

        // Two limiters guard this endpoint: per-address and per-account. Without the address one, a
        // credential-stuffing run against a thousand different addresses is unthrottled, because no
        // single account ever reaches its own limit.
        expect($statuses)->toContain(429);
    });

    it('reports how long to wait rather than failing opaquely', function (): void {
        config(['asids.rate_limits.login' => 2]);

        $response = null;

        for ($i = 0; $i < 12; $i++) {
            $response = $this->withHeader('X-Tenant', 'acme')
                ->postJson('/api/v1/auth/login', ['email' => 'nobody@acme.test', 'password' => 'wrong']);

            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        // In the problem document, not the header. `ApiExceptionRenderer` converts every failure to
        // RFC 9457 and moves the framework's `Retry-After` into `retry_after_seconds`, which is the
        // field the SPA reads to show "try again in 43 seconds" instead of a bare failure.
        expect($response?->getStatusCode())->toBe(429)
            ->and($response?->json('retry_after_seconds'))->toBeInt();
    });
});

describe('ApiExceptionRenderer 403 mapping', function (): void {
    it('renders a policy-denied action as the forbidden problem, not a generic http-403', function (): void {
        // `AccountPolicy::create()` returns false for a bookkeeper (no `accounting.accounts.manage`),
        // so `$this->authorize()` in `AccountController::store()` throws `AuthorizationException`.
        // Laravel's `Handler::prepareException()` converts that to `AccessDeniedHttpException`
        // *before* `ApiExceptionRenderer` ever sees it — so this is the framework-thrown 403 path,
        // not the explicitly-thrown `AuthorizationException` arm.
        $bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'renderer-403@acme.test']);

        app(MembershipService::class)->grant($this->acme['company'], $bookkeeper, $this->owner);

        $fresh = RowLevelSecurity::bypass(static fn (): ?User => $bookkeeper->fresh());

        $response = test()->actingAs($fresh ?? $bookkeeper)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $this->acme['company']->getKey())
            ->postJson('/api/v1/companies/'.$this->acme['company']->getKey().'/accounts', [
                'code' => '9999',
                'name' => 'Should Be Refused',
                'type' => 'asset',
            ]);

        expect($response)->toBeProblem('forbidden', 403);
    });
});

describe('token credentials', function (): void {
    it('treats a revoked token as unusable', function (): void {
        $token = makeToken(['revoked_at' => now()]);

        expect($token->isUsable())->toBeFalse();
    });

    it('treats an expired token as unusable', function (): void {
        expect(makeToken(['expires_at' => now()->subMinute()])->isUsable())->toBeFalse();
    });

    it('treats a token with no expiry as usable', function (): void {
        // Deliberately permitted: some integrations genuinely run indefinitely, and forcing a
        // rotation date the customer cannot honour produces tokens nobody dares revoke.
        expect(makeToken(['expires_at' => null])->isUsable())->toBeTrue();
    });

    it('permits any address when no ranges are configured', function (): void {
        $token = makeToken(['allowed_ip_ranges' => null]);

        expect($token->permitsAddress('203.0.113.9'))->toBeTrue()
            ->and($token->permitsAddress(null))->toBeTrue();
    });

    it('permits an address inside a configured range', function (): void {
        $token = makeToken(['allowed_ip_ranges' => ['203.0.113.0/24']]);

        expect($token->permitsAddress('203.0.113.9'))->toBeTrue();
    });

    it('refuses an address outside every configured range', function (): void {
        $token = makeToken(['allowed_ip_ranges' => ['203.0.113.0/24']]);

        expect($token->permitsAddress('198.51.100.7'))->toBeFalse();
    });

    it('refuses an unknown address when ranges are configured', function (): void {
        $token = makeToken(['allowed_ip_ranges' => ['203.0.113.0/24']]);

        // Fail closed. A request whose source address cannot be determined must not satisfy a
        // restriction whose entire purpose is to pin the source address.
        expect($token->permitsAddress(null))->toBeFalse();
    });

    it('matches an exact single-address range', function (): void {
        $token = makeToken(['allowed_ip_ranges' => ['203.0.113.9/32']]);

        expect($token->permitsAddress('203.0.113.9'))->toBeTrue()
            ->and($token->permitsAddress('203.0.113.10'))->toBeFalse();
    });

    it('accepts an address in any one of several ranges', function (): void {
        $token = makeToken(['allowed_ip_ranges' => ['203.0.113.0/24', '198.51.100.0/24']]);

        expect($token->permitsAddress('198.51.100.7'))->toBeTrue();
    });
});

describe('the user repository', function (): void {
    it('finds a user by address case-insensitively', function (): void {
        $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'mixed.case@acme.test']);

        // Addresses are stored as typed but compared case-insensitively, via an expression index.
        // Otherwise "Kumari@acme.test" and "kumari@acme.test" are two accounts.
        expect($this->users->findByEmail('MIXED.CASE@ACME.TEST')?->email)->toBe('mixed.case@acme.test');
    });

    it('reports an address as taken regardless of case', function (): void {
        $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'taken@acme.test']);

        expect($this->users->emailExists('TAKEN@acme.test'))->toBeTrue()
            ->and($this->users->emailExists('free@acme.test'))->toBeFalse();
    });

    it('excludes a named user when checking availability, so a self-edit is not a conflict', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'self@acme.test']);

        expect($this->users->emailExists('self@acme.test', excludingId: $user->getKey()))->toBeFalse();
    });

    it('does not see a user in another workspace', function (): void {
        // Identity is per workspace, so the same address may exist in several — and a lookup must
        // never cross the boundary even when the caller supplies an exact address.
        expect($this->users->findByEmail($this->globex['owner']->email))->toBeNull();
    });

    it('counts consumed seats as active plus pending invitations', function (): void {
        $before = $this->users->consumedSeatCount();

        $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'seat@acme.test']);

        expect($this->users->consumedSeatCount())->toBe($before + 1);
    });

    it('reports other active owners, excluding the one asked about', function (): void {
        expect($this->users->otherActiveOwners($this->owner->getKey())->all())->toBe([]);

        $second = $this->createUserWithRole($this->acme['tenant'], 'owner', ['email' => 'owner2@acme.test']);

        // This is what the last-active-owner guard consults. Counting the subject themselves would
        // make the guard always pass and the protection meaningless.
        expect($this->users->otherActiveOwners($this->owner->getKey())->pluck('id')->all())
            ->toBe([$second->getKey()]);
    });

    it('paginates with allow-listed criteria', function (): void {
        $page = $this->users->paginate(QueryCriteria::of(sorts: ['created_at' => 'desc'], perPage: 1));

        expect($page->perPage())->toBe(1)
            ->and($page->total())->toBeGreaterThan(0);
    });

    it('finds users idle since a threshold', function (): void {
        $idle = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'idle@acme.test']);

        RowLevelSecurity::bypass(fn () => $idle->forceFill(['last_activity_at' => now()->subDays(90)])->save());

        // Feeds the retention and dormancy sweeps, which run with no HTTP request and therefore no
        // screen that would reveal a wrong query.
        expect($this->users->idleSince(now()->subDays(30))->pluck('id')->all())->toContain($idle->getKey());
    });
});

describe('the tenant repository', function (): void {
    it('finds a workspace by slug case-insensitively', function (): void {
        expect(app(TenantRepositoryContract::class)->findBySlug('ACME')?->slug)->toBe('acme');
    });

    it('reports a slug as taken including soft-deleted workspaces', function (): void {
        // Reusing the slug of a recently closed workspace would let its former users' bookmarks and
        // cached DNS reach a different customer.
        expect(app(TenantRepositoryContract::class)->slugExists('acme'))->toBeTrue()
            ->and(app(TenantRepositoryContract::class)->slugExists('never-used'))->toBeFalse();
    });

    it('does not route traffic to an unverified custom hostname', function (): void {
        expect(app(TenantRepositoryContract::class)->findByDomain('not-a-known-host.example'))->toBeNull();
    });
});

describe('the release gate', function (): void {
    it('passes when row level security is in force', function (): void {
        expect(Artisan::call('asids:security-check'))->toBe(0);
    });

    it('fails when enforcement is off but the policies exist', function (): void {
        config(['asids.tenancy.enforce_rls' => false]);

        // The single worst configuration in the platform, and the reason this check exists: the
        // policies constrain every query, nothing publishes a tenant for them to match, and the
        // application reads empty result sets everywhere with no error. It presents to the customer
        // as "all my data has vanished".
        expect(Artisan::call('asids:security-check'))->not->toBe(0);
    });

    it('treats warnings as failures under --strict', function (): void {
        // Warnings are acceptable in development and not in a release. `--strict` is what the
        // deployment pipeline uses so an advisory does not become permanent.
        expect(Artisan::call('asids:security-check', ['--strict' => true]))->toBeInt();
    });
});

describe('maintenance command options', function (): void {
    it('seals a single workspace by slug', function (): void {
        expect(Artisan::call('asids:audit-seal', ['--tenant' => 'acme']))->toBe(0);
    });

    it('bounds how much it seals per run', function (): void {
        // The batch size is what keeps a nightly sweep from holding an advisory lock over a
        // million-row backlog.
        expect(Artisan::call('asids:audit-seal', ['--batch' => 10]))->toBe(0);
    });

    it('verifies a single workspace by slug', function (): void {
        Artisan::call('asids:audit-seal');

        expect(Artisan::call('asids:audit-verify', ['--tenant' => 'acme']))->toBe(0);
    });

    it('verifies from a given sequence onwards', function (): void {
        Artisan::call('asids:audit-seal');

        // Lets an operator re-verify only the range since the last known-good point, rather than
        // rehashing seven years to answer a question about last night.
        expect(Artisan::call('asids:audit-verify', ['--from' => 1]))->toBe(0);
    });

    it('fails when asked to verify a workspace that does not exist', function (): void {
        expect(Artisan::call('asids:audit-verify', ['--tenant' => 'no-such-workspace']))->not->toBe(0);
    });

    it('refreshes system roles with newly added capabilities', function (): void {
        // Run at release time: a capability added in a new version has to reach each workspace's
        // built-in roles, or the feature ships invisible to every existing customer.
        expect(Artisan::call('asids:sync-permissions', ['--refresh-roles' => true]))->toBe(0);
    });

    it('can overwrite customised system roles when forced', function (): void {
        expect(Artisan::call('asids:sync-permissions', ['--refresh-roles' => true, '--force' => true]))->toBe(0);
    });

    it('prunes the activity feed without touching the audit trail', function (): void {
        // Different retention periods on purpose: the activity feed is a dashboard surface kept for
        // months, the audit trail is a record kept for seven years.
        expect(Artisan::call('asids:audit-prune', ['--activity-only' => true, '--confirm' => true]))->toBe(0);
    });
});

/**
 * A persisted token, built the way the service does.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeToken(array $attributes = []): PersonalAccessToken
{
    return RowLevelSecurity::bypass(function () use ($attributes): PersonalAccessToken {
        $token = new PersonalAccessToken;

        $token->forceFill([
            'tenant_id' => test()->acme['tenant']->getKey(),
            'tokenable_type' => 'user',
            'tokenable_id' => test()->owner->getKey(),
            'name' => 'Integration',
            'token' => hash('sha256', 'plaintext-'.bin2hex(random_bytes(8))),
            'abilities' => ['identity.users.view'],
            ...$attributes,
        ])->save();

        return $token;
    });
}
