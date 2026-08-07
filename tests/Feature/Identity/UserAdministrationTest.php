<?php

declare(strict_types=1);

use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * The user administration HTTP surface, end to end.
 *
 * Exercised through the router rather than the service, because the properties that matter here are
 * produced by the *stack*: the tenant resolver, the policy, the form request, the service and the
 * response envelope. A service-level test of "suspend refuses the last owner" says nothing about
 * whether the route is reachable by an accountant.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->administrator = $this->createUserWithRole($this->acme['tenant'], 'administrator', [
        'email' => 'admin@acme.test',
    ]);
    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);
});

/**
 * Issues a request as a user, inside their workspace.
 *
 * The `X-Tenant` header is not optional even when authenticated: `users` is tenant scoped, so a
 * request that resolves no workspace reads an empty table and the assertions become vacuous.
 */
function asUser(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    // `fresh()`, not the factory's instance. Model strictness is on
    // (`preventAccessingMissingAttributes`), and a factory-made model has only the attributes the
    // factory named — columns that merely carry a database default, such as
    // `must_change_password`, are absent from it. Over real HTTP the session guard loads the row,
    // so every column is present; handing the middleware an un-reloaded instance would fail on a
    // difference between the test and production rather than on the behaviour under test.
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

describe('listing users', function (): void {
    it('returns the workspace’s users in the standard envelope', function (): void {
        $response = asUser($this->owner, 'GET', '/api/v1/users');

        expect($response)->toBeEnvelope();

        $emails = collect($response->json('data'))->pluck('email')->all();

        expect($emails)->toContain('admin@acme.test', 'accountant@acme.test');
    });

    it('never returns another workspace’s users', function (): void {
        $response = asUser($this->owner, 'GET', '/api/v1/users');

        $ids = collect($response->json('data'))->pluck('id')->all();

        // The attacker's best case is a valid identifier from elsewhere. The scope is what makes it
        // useless, and this asserts it at the edge rather than at the query builder.
        expect($ids)->not->toContain($this->globex['owner']->getKey());
    });

    it('reports seat usage alongside the list', function (): void {
        $response = asUser($this->owner, 'GET', '/api/v1/users');

        // The administrator deciding whether to invite someone is looking at exactly this screen,
        // so the number travels with the list rather than in a second request.
        expect($response->json('meta.seats.consumed'))->toBeInt()
            ->and($response->json('meta.seats'))->toHaveKey('limit');
    });

    it('refuses a user without the view permission', function (): void {
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');

        $response = asUser($viewer, 'GET', '/api/v1/users');

        expect($response->getStatusCode())->toBe(403);
    });

    it('does not leak credentials in the list', function (): void {
        $response = asUser($this->owner, 'GET', '/api/v1/users');

        expect($response)->toNotExposeFields(
            'password',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'remember_token',
        );
    });
});

describe('inviting', function (): void {
    it('creates a pending user and sends the invitation', function (): void {
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'Sunil',
            'last_name' => 'Rathnayake',
            'email' => 'sunil@example.com',
            'role_ids' => [$this->acme['roles']['bookkeeper']->getKey()],
            'company_ids' => [],
        ]);

        expect($response->getStatusCode())->toBe(201);

        $invited = User::query()->where('email', 'sunil@example.com')->firstOrFail();

        // Pending, not active, and with no password: the invitation link is the only way in, which
        // is what stops an administrator from knowing anyone else's credential.
        expect($invited->status)->toBe(UserStatus::PendingInvitation)
            ->and($invited->password)->toBeNull()
            ->and($invited->invited_by_id)->toBe($this->owner->getKey())
            ->and($invited->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('refuses an address already used in the workspace', function (): void {
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'Duplicate',
            'email' => 'accountant@acme.test',
            'role_ids' => [],
            'company_ids' => [],
        ]);

        expect($response)->toBeProblem('duplicate-resource');
    });

    it('permits an address that exists in a different workspace', function (): void {
        // Identity is per workspace: the same accountant may serve several client businesses, and
        // a global unique index on e-mail would also tell anyone which workspaces an address is in.
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'Shared',
            'email' => $this->globex['owner']->email,
            'role_ids' => [],
            'company_ids' => [],
        ]);

        expect($response->getStatusCode())->toBe(201);
    });

    it('refuses an invitation once the seat limit is reached', function (): void {
        // Three accounts already exist in acme — owner, administrator, accountant — and globex's
        // users are in a different workspace, so they do not count.
        RowLevelSecurity::bypass(fn () => $this->acme['tenant']->forceFill(['max_users' => 3])->save());
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'One',
            'email' => 'one@example.com',
            'role_ids' => [],
            'company_ids' => [],
        ]);

        // 402, not 422: this is commercial, and the client shows an upgrade path rather than
        // pinning an error to a form field the user cannot fix by editing it.
        expect($response)->toBeProblem('seat-limit-reached')
            ->and($response->getStatusCode())->toBe(402);
    });

    it('counts pending invitations against the seat limit', function (): void {
        RowLevelSecurity::bypass(fn () => $this->acme['tenant']->forceFill(['max_users' => 4])->save());

        asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'First', 'email' => 'first@example.com', 'role_ids' => [], 'company_ids' => [],
        ])->assertStatus(201);

        // An invitation that has been sent has effectively consumed the seat. Counting only accepted
        // ones lets a workspace invite past its limit and discover it as people start accepting.
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => 'Second', 'email' => 'second@example.com', 'role_ids' => [], 'company_ids' => [],
        ]);

        expect($response)->toBeProblem('seat-limit-reached');
    });

    it('refuses a user without the invite permission', function (): void {
        $response = asUser($this->accountant, 'POST', '/api/v1/users', [
            'first_name' => 'Nope', 'email' => 'nope@example.com', 'role_ids' => [], 'company_ids' => [],
        ]);

        expect($response->getStatusCode())->toBe(403);
    });

    it('validates the payload rather than storing a partial user', function (): void {
        $response = asUser($this->owner, 'POST', '/api/v1/users', [
            'first_name' => '',
            'email' => 'not-an-address',
            'role_ids' => [],
            'company_ids' => [],
        ]);

        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKeys(['first_name', 'email']);
    });
});

describe('suspending and reinstating', function (): void {
    it('suspends a user', function (): void {
        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/suspend", [
            'reason' => 'Under investigation',
        ]);

        expect($response)->toBeEnvelope()
            ->and($this->accountant->refresh()->status)->toBe(UserStatus::Suspended);
    });

    it('stops a suspended user authorising anything, without waiting for their next sign-in', function (): void {
        asUser($this->owner, 'POST', "/api/v1/users/{$this->administrator->getKey()}/suspend", [
            'reason' => 'Leaver',
        ]);

        // The `Gate::after` inactive-account check is what makes this immediate. Without it a
        // suspended administrator keeps their session's authority until it expires.
        expect($this->administrator->refresh()->can('identity.users.view'))->toBeFalse();
    });

    it('refuses to suspend your own account', function (): void {
        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->owner->getKey()}/suspend", [
            'reason' => 'Mistake',
        ]);

        // Locking yourself out of the workspace you administer is a support call, not a feature.
        expect($response)->toBeProblem('cannot-act-on-self');
    });

    it('refuses an administrator any action against an owner', function (): void {
        $response = asUser($this->administrator, 'POST', "/api/v1/users/{$this->owner->getKey()}/suspend", [
            'reason' => 'Attempted takeover',
        ]);

        // 403 from the policy, which requires the actor to *outrank* the target strictly. That is
        // what actually protects the owner over HTTP — and it means `UserService`'s own
        // last-active-owner guard is never reached through the router. The guard is still worth
        // having and is exercised directly below; asserting the code that really comes back is what
        // keeps this test from claiming to cover a path it does not reach.
        expect($response->getStatusCode())->toBe(403)
            ->and($this->owner->refresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to strand a workspace with no active owner, even when the policy is bypassed', function (): void {
        // Called on the service directly: no HTTP caller can get here, because nobody outranks an
        // owner. A console command, a data fix or a future endpoint that forgets the policy can, and
        // a workspace with no owner cannot grant the owner role back to anyone — it is unrecoverable
        // without ASIDS editing the database by hand.
        $exception = catchPlatformException(fn () => app(UserService::class)
            ->suspend($this->owner, 'Direct call', $this->administrator));

        expect($exception->problemCode())->toBe('last-active-owner');
    });

    it('reinstates a suspended user', function (): void {
        asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/suspend", ['reason' => 'Temporary']);

        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/reinstate");

        expect($response)->toBeEnvelope()
            ->and($this->accountant->refresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to reinstate past the seat limit', function (): void {
        asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/suspend", ['reason' => 'Temporary']);

        // Two active accounts remain, so a limit of 2 leaves no room to bring the third back.
        RowLevelSecurity::bypass(fn () => $this->acme['tenant']->forceFill(['max_users' => 2])->save());

        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/reinstate");

        expect($response)->toBeProblem('seat-limit-reached');
    });
});

describe('deactivating', function (): void {
    it('deactivates a user, retaining the record for audit attribution', function (): void {
        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->accountant->getKey()}/deactivate", [
            'reason' => 'Left the company',
        ]);

        expect($response)->toBeEnvelope();

        $deactivated = $this->accountant->refresh();

        // Never deleted: entries in the audit trail attribute changes to this user, and those must
        // stay resolvable for as long as the records are retained.
        expect($deactivated->status)->toBe(UserStatus::Deactivated)
            ->and($deactivated->deactivated_at)->not->toBeNull()
            ->and($deactivated->deactivation_reason)->toBe('Left the company')
            ->and(User::query()->whereKey($deactivated->getKey())->exists())->toBeTrue();
    });

    it('refuses to deactivate the last active owner when the policy is bypassed', function (): void {
        $exception = catchPlatformException(fn () => app(UserService::class)
            ->deactivate($this->owner, 'Direct call', $this->administrator));

        // Deactivation is irreversible, so this backstop matters more here than on suspension.
        expect($exception->problemCode())->toBe('last-active-owner')
            ->and($this->owner->refresh()->status)->toBe(UserStatus::Active);
    });

    it('permits deactivating an owner once another owner exists', function (): void {
        $second = $this->createUserWithRole($this->acme['tenant'], 'owner', ['email' => 'owner2@acme.test']);

        $response = asUser($second, 'POST', "/api/v1/users/{$this->owner->getKey()}/deactivate", [
            'reason' => 'Handover complete',
        ]);

        // The rule is "never zero owners", not "never touch an owner".
        expect($response)->toBeEnvelope()
            ->and($this->owner->refresh()->status)->toBe(UserStatus::Deactivated);
    });

    it('refuses a user without the deactivate permission', function (): void {
        $response = asUser($this->accountant, 'POST', "/api/v1/users/{$this->administrator->getKey()}/deactivate", [
            'reason' => 'No authority',
        ]);

        expect($response->getStatusCode())->toBe(403);
    });
});

describe('cross workspace access', function (): void {
    it('cannot read a user in another workspace even with their identifier', function (): void {
        $response = asUser($this->owner, 'GET', "/api/v1/users/{$this->globex['owner']->getKey()}");

        // 404, not 403: telling the caller the record exists but is forbidden confirms that an
        // identifier belongs to some other customer.
        expect($response->getStatusCode())->toBe(404);
    });

    it('cannot suspend a user in another workspace', function (): void {
        $response = asUser($this->owner, 'POST', "/api/v1/users/{$this->globex['owner']->getKey()}/suspend", [
            'reason' => 'Cross tenant attempt',
        ]);

        // Refreshed inside globex: reloading a foreign row from inside acme is itself scoped out,
        // which would fail the assertion for the very reason the test is asserting.
        $stillActive = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn (): UserStatus => $this->globex['owner']->refresh()->status,
        );

        expect($response->getStatusCode())->toBe(404)
            ->and($stillActive)->toBe(UserStatus::Active);
    });
});

describe('the session endpoint', function (): void {
    it('returns everything the shell needs in one call', function (): void {
        $response = asUser($this->owner, 'GET', '/api/v1/auth/session');

        // The shell cannot render until all of these arrive. Four requests would mean four
        // opportunities for a partially-rendered interface.
        expect($response)->toBeEnvelope()
            ->and($response->json('data'))
            ->toHaveKeys(['authenticated', 'user', 'permissions', 'workspace', 'companies', 'requires']);
    });

    it('reports the permissions the user actually holds', function (): void {
        $response = asUser($this->accountant, 'GET', '/api/v1/auth/session');

        $permissions = $response->json('data.permissions');

        // The front end hides what a user cannot do based on this list. If it over-reports, the
        // interface offers actions that then 403.
        expect($permissions)->toBeArray()
            ->and($permissions)->not->toContain('identity.users.invite');
    });

    it('refuses an unauthenticated caller with a problem document', function (): void {
        $response = $this->withHeader('X-Tenant', 'acme')->getJson('/api/v1/users');

        expect($response->getStatusCode())->toBe(401)
            ->and($response->json())->toHaveKeys(['type', 'title', 'status', 'detail']);
    });
});
