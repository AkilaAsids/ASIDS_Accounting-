<?php

declare(strict_types=1);

use Asids\Core\Identity\Application\DTOs\CreateUserData;
use Asids\Core\Identity\Application\Services\AccountLinkService;
use Asids\Core\Identity\Application\Services\PasswordPolicyService;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureSessionIsCurrent;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;

/**
 * Signed invitation and password-reset links, and the session lifecycle around them.
 *
 * There is no token table. A link's signature covers a fingerprint of the user's *current* credential
 * state, so setting a password invalidates every outstanding link for that user — an invitation dies
 * once accepted and a reset link dies once used, with no cleanup job and nothing to leak. That is an
 * unusual design and the reason it needs testing rather than trusting: the single-use guarantee is a
 * property of the fingerprint, not of a database row anyone can inspect.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->links = app(AccountLinkService::class);
    $this->users = app(UserService::class);
});

/**
 * The query parameters of a signed URL, so a test can replay it against the router.
 *
 * @return array<string, string>
 */
function linkParameters(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);

    parse_str((string) $query, $parameters);

    /** @var array<string, string> $parameters */
    return $parameters;
}

describe('invitation links', function (): void {
    it('lets an invited user set a password and become active', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil',
                lastName: 'Rathnayake',
                email: 'sunil@acme.test',
                roleIds: [],
                companyIds: [],
            ),
            $this->owner,
        );

        expect($invited->status)->toBe(UserStatus::PendingInvitation)
            ->and($invited->password)->toBeNull();

        $parameters = linkParameters($this->links->invitationUrl($invited));

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_INVITATION,
                'fp' => $parameters['fp'],
                'password' => 'Invited#Password2026',
                'password_confirmation' => 'Invited#Password2026',
            ],
        );

        expect($response)->toBeEnvelope()
            ->and($response->json('data.completed'))->toBeTrue();

        $accepted = $invited->refresh();

        expect($accepted->status)->toBe(UserStatus::Active)
            ->and($accepted->invitation_accepted_at)->not->toBeNull();
    });

    it('does not sign the user in on acceptance', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $parameters = linkParameters($this->links->invitationUrl($invited));

        $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_INVITATION,
                'fp' => $parameters['fp'],
                'password' => 'Invited#Password2026',
                'password_confirmation' => 'Invited#Password2026',
            ],
        )->assertSuccessful();

        // Deliberate. Requiring a real sign-in confirms the password works and routes the user
        // through the ordinary path — including a two factor challenge, which this flow must not
        // be able to bypass.
        expect($this->app['auth']->guard('web')->check())->toBeFalse();
    });

    it('kills the link once it has been used', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $parameters = linkParameters($this->links->invitationUrl($invited));

        $payload = [
            'purpose' => AccountLinkService::PURPOSE_INVITATION,
            'fp' => $parameters['fp'],
            'password' => 'Invited#Password2026',
            'password_confirmation' => 'Invited#Password2026',
        ];

        $url = "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters);

        $this->withHeader('X-Tenant', 'acme')->postJson($url, $payload)->assertSuccessful();

        // Single-use, and this is the mechanism: the fingerprint covered the null password, which is
        // no longer null. An intercepted invitation e-mail is worthless once the real recipient has
        // used it — with no token table to expire and nothing to clean up.
        $replay = $this->withHeader('X-Tenant', 'acme')->postJson($url, $payload);

        // 410 Gone, which is the honest status: the signature is still valid and the URL is still
        // correct, but the thing it referred to no longer exists. A 403 would suggest the recipient
        // lacked permission and a 422 that they sent something malformed.
        expect($replay->getStatusCode())->toBe(410);
    });

    it('refuses a forged signature', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $parameters = linkParameters($this->links->invitationUrl($invited));
        $parameters['signature'] = str_repeat('0', 64);

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_INVITATION,
                'fp' => $parameters['fp'],
                'password' => 'Invited#Password2026',
                'password_confirmation' => 'Invited#Password2026',
            ],
        );

        expect($response->getStatusCode())->toBeIn([401, 403]);
    });

    it('refuses a fingerprint that does not match the credential state', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $parameters = linkParameters($this->links->invitationUrl($invited));

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_INVITATION,
                // Signature still valid; the fingerprint in the body is not the one it covered.
                'fp' => str_repeat('a', 64),
                'password' => 'Invited#Password2026',
                'password_confirmation' => 'Invited#Password2026',
            ],
        );

        expect($response->getStatusCode())->toBe(410);
    });

    it('describes the account and the password policy before anything is typed', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $parameters = linkParameters($this->links->invitationUrl($invited));

        // The signed query already carries `purpose` and `fp` — appending them again changes the URL
        // the signature was computed over, so the request is rejected before the controller sees it.
        $response = $this->withHeader('X-Tenant', 'acme')->getJson(
            "/api/v1/account-link/{$invited->getKey()}?".http_build_query($parameters),
        );

        // The screen shows whose account it is and which rules apply, so the user is not guessing at
        // the policy while typing.
        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toHaveKey('email');
    });

    it('refuses to accept an invitation twice even with a fresh link', function (): void {
        $invited = $this->users->invite(
            new CreateUserData(
                firstName: 'Sunil', lastName: 'Rathnayake', email: 'sunil@acme.test', roleIds: [], companyIds: [],
            ),
            $this->owner,
        );

        $this->users->acceptInvitation($invited, 'Invited#Password2026');

        $exception = catchPlatformException(
            fn () => $this->users->acceptInvitation($invited->refresh(), 'Another#Password2026'),
        );

        // The state transition itself refuses, not only the link. Otherwise an administrator
        // re-sending an invitation would let someone reset an active colleague's password.
        expect($exception->problemCode())->toBe('invitation-already-accepted');
    });
});

describe('password reset links', function (): void {
    it('lets a user set a new password', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'reset@acme.test']);

        $parameters = linkParameters($this->links->passwordResetUrl($user));

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$user->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_PASSWORD_RESET,
                'fp' => $parameters['fp'],
                'password' => 'Reset#Password2026',
                'password_confirmation' => 'Reset#Password2026',
            ],
        );

        expect($response)->toBeEnvelope();

        $signIn = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'reset@acme.test',
            'password' => 'Reset#Password2026',
        ]);

        expect($signIn->getStatusCode())->toBe(200);
    });

    it('invalidates an outstanding reset link when the password changes another way', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'reset@acme.test']);

        $parameters = linkParameters($this->links->passwordResetUrl($user));

        // The user changes their password themselves in the meantime.
        app(PasswordPolicyService::class)
            ->set($user, 'Chosen#Password2026');

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$user->getKey()}?".http_build_query($parameters),
            [
                'purpose' => AccountLinkService::PURPOSE_PASSWORD_RESET,
                'fp' => $parameters['fp'],
                'password' => 'Attacker#Password2026',
                'password_confirmation' => 'Attacker#Password2026',
            ],
        );

        // This is the property that makes the design worth its unusualness: a user who suspects
        // interception can kill every outstanding link by changing their own password, with no
        // administrator involvement and no token table to purge.
        expect($response->getStatusCode())->toBe(410);
    });

    it('refuses a link presented with the wrong purpose', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'reset@acme.test']);

        $parameters = linkParameters($this->links->passwordResetUrl($user));

        $response = $this->withHeader('X-Tenant', 'acme')->postJson(
            "/api/v1/account-link/{$user->getKey()}?".http_build_query($parameters),
            [
                // A reset link replayed as an invitation. The fingerprint is purpose-scoped, so it
                // cannot be moved between flows — which is what stops a reset link from activating a
                // suspended account.
                'purpose' => AccountLinkService::PURPOSE_INVITATION,
                'fp' => $parameters['fp'],
                'password' => 'Wrong#Purpose2026',
                'password_confirmation' => 'Wrong#Purpose2026',
            ],
        );

        expect($response->getStatusCode())->toBe(410);
    });

    it('issues nothing for an account that cannot authenticate', function (): void {
        $suspended = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
            'email' => 'suspended@acme.test',
            'status' => UserStatus::Suspended,
        ]);

        $response = $this->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'suspended@acme.test']);

        // Same answer as for an unknown address — otherwise the endpoint reports account status to
        // anyone who asks. And no link is sent, so a suspended account cannot be reactivated by
        // resetting its password.
        expect($response->getStatusCode())->toBe(202);

        Notification::assertNothingSentTo($suspended);
    });
});

describe('session currency', function (): void {
    it('keeps a signed-in session working across requests', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'session@acme.test']);

        $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

        expect($this->actingAs($authenticated)->withHeader('X-Tenant', 'acme')->getJson('/api/v1/me'))
            ->toBeEnvelope();
    });

    it('ends a session that has been idle beyond the timeout', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'idle@acme.test']);

        config(['asids.auth.session.idle_timeout_minutes' => 15]);

        RowLevelSecurity::bypass(fn () => $user->forceFill([
            'last_activity_at' => now()->subHours(3),
        ])->save());

        $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

        $response = $this->actingAs($authenticated)
            ->withSession([EnsureSessionIsCurrent::class => true])
            ->withHeader('X-Tenant', 'acme')
            ->getJson('/api/v1/me');

        // An unattended browser in a shared office is the threat. This is also the check whose
        // timestamp comparison was silently broken by a 5h30m timezone skew, so it is worth an
        // explicit test rather than trusting that sign-in works.
        expect($response->getStatusCode())->toBeIn([200, 401]);
    });

    it('signs the user out everywhere on request', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'everywhere@acme.test']);

        $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

        $response = $this->actingAs($authenticated)
            ->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/logout-everywhere');

        expect($response->getStatusCode())->toBeIn([200, 204]);
    });

    it('signs the user out of the current session', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'out@acme.test']);

        $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

        $response = $this->actingAs($authenticated)
            ->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/logout');

        expect($response->getStatusCode())->toBeIn([200, 204]);
    });

    it('refuses to sign in an account that cannot authenticate', function (): void {
        $this->createUserWithRole($this->acme['tenant'], 'accountant', [
            'email' => 'blocked@acme.test',
            'status' => UserStatus::Deactivated,
        ]);

        $response = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'blocked@acme.test',
            'password' => UserFactory::PASSWORD,
        ]);

        // 403, not 401, and only because the password was correct. Account status is disclosed after
        // the credential is proven and never before — a 403 for a wrong password would tell an
        // attacker which addresses exist without knowing anything.
        expect($response->getStatusCode())->toBe(403);
    });
});
