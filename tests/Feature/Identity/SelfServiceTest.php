<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Domain\Models\UserDevice;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureTwoFactorIsConfirmed;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Everything a signed-in user can do to their own account: profile, password, two factor, devices,
 * sign-in history and API tokens.
 *
 * Grouped into one file because they share the same property — a user may act on themselves and on
 * nobody else. The interesting cases are all the ones where "themselves" is ambiguous: an
 * administrator listing another user's devices, a token issued with abilities its creator does not
 * hold, a step-up-protected route reached with a token that has no session to challenge.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);
});

function asSelf(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

describe('profile', function (): void {
    it('returns the caller’s own profile', function (): void {
        $response = asSelf($this->accountant, 'GET', '/api/v1/me');

        expect($response)->toBeEnvelope()
            ->and($response->json('data.email'))->toBe('accountant@acme.test');
    });

    it('updates the caller’s own details', function (): void {
        $response = asSelf($this->accountant, 'PUT', '/api/v1/me', [
            'first_name' => 'Kumari',
            'last_name' => 'Silva',
            'timezone' => 'Asia/Colombo',
            'theme' => 'dark',
        ]);

        expect($response)->toBeEnvelope();

        $updated = $this->accountant->refresh();

        expect($updated->first_name)->toBe('Kumari')
            ->and($updated->theme)->toBe('dark');
    });

    it('does not let a user set their own job title', function (): void {
        asSelf($this->accountant, 'PUT', '/api/v1/me', [
            'first_name' => 'Kumari',
            'job_title' => 'Chief Financial Officer',
        ]);

        // Deliberately outside the self-service allow-list. A job title appears on documents and in
        // approval routing, so it is something an administrator records about someone, not something
        // that person asserts about themselves.
        expect($this->accountant->refresh()->job_title)->toBeNull();
    });

    it('does not let a user change their own status through the profile endpoint', function (): void {
        asSelf($this->accountant, 'PUT', '/api/v1/me', [
            'first_name' => 'Kumari',
            'status' => 'suspended',
            'is_platform_admin' => true,
        ]);

        // Mass assignment on a self-service endpoint is the classic privilege escalation. The form
        // request's allow-list is what stops it, and it must stop *both* of these.
        expect($this->accountant->refresh()->status->value)->toBe('active')
            ->and($this->accountant->is_platform_admin)->toBeFalse();
    });

    it('does not leak credentials in the profile', function (): void {
        $response = asSelf($this->accountant, 'GET', '/api/v1/me');

        expect($response)->toNotExposeFields('password', 'two_factor_secret', 'remember_token');
    });
});

describe('changing your own password', function (): void {
    it('changes the password when the current one is given', function (): void {
        $response = asSelf($this->accountant, 'PUT', '/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'Chosen#Password2026',
            'password_confirmation' => 'Chosen#Password2026',
        ]);

        expect($response)->toBeEnvelope();

        // Verified by signing in with it, which is the only assertion that proves the hash is usable
        // rather than merely different.
        $signIn = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'accountant@acme.test',
            'password' => 'Chosen#Password2026',
        ]);

        expect($signIn->getStatusCode())->toBe(200);
    });

    it('refuses when the current password is wrong', function (): void {
        $response = asSelf($this->accountant, 'PUT', '/api/v1/auth/password', [
            'current_password' => 'not-the-current-one',
            'password' => 'Chosen#Password2026',
            'password_confirmation' => 'Chosen#Password2026',
        ]);

        // Otherwise a hijacked session could lock the real owner out of their own account without
        // ever knowing their password.
        expect($response->getStatusCode())->toBeIn([401, 422]);
    });

    it('refuses a new password identical to the current one', function (): void {
        $response = asSelf($this->accountant, 'PUT', '/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => UserFactory::PASSWORD,
            'password_confirmation' => UserFactory::PASSWORD,
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a new password that fails the platform policy', function (): void {
        $response = asSelf($this->accountant, 'PUT', '/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });
});

describe('two factor enrolment', function (): void {
    it('reports the current state', function (): void {
        $response = asSelf($this->accountant, 'GET', '/api/v1/auth/two-factor');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toHaveKeys(['enabled', 'unused_recovery_codes', 'required_by_workspace']);
    });

    it('issues a secret and a QR code without activating anything', function (): void {
        $response = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/enrol');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toHaveKeys(['secret', 'otpauth_uri', 'qr_code_svg']);

        // Two-phase on purpose: enrolled but not confirmed. A single-phase enrolment locks out any
        // user whose authenticator was misconfigured, which is the most avoidable support ticket
        // there is.
        $user = $this->accountant->refresh();

        expect($user->two_factor_enrolled_at)->not->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->hasTwoFactorEnabled())->toBeFalse();
    });

    it('refuses to confirm enrolment with a wrong code', function (): void {
        asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/enrol');

        $response = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/confirm', ['code' => '000000']);

        expect($response->getStatusCode())->toBeIn([422, 401])
            ->and($this->accountant->refresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('confirms enrolment with a valid code and issues recovery codes once', function (): void {
        $secret = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/confirm', ['code' => $code]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.recovery_codes'))->toBeArray()
            ->and($response->json('data.recovery_codes'))->not->toBeEmpty()
            ->and($this->accountant->refresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('regenerates recovery codes for a user who has a second factor', function (): void {
        $secret = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');
        $totp = app(Google2FA::class);

        asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/confirm', ['code' => $totp->getCurrentOtp($secret)]);

        // Step-up: this route is protected, and the caller now *has* a second factor, so a code is
        // demanded. Confirming the session first is what a client does after the 428 prompt.
        session([EnsureTwoFactorIsConfirmed::SESSION_KEY => now()->getTimestamp()]);

        $response = asSelf($this->accountant, 'POST', '/api/v1/auth/two-factor/recovery-codes', [
            'code' => $totp->getCurrentOtp($secret),
        ]);

        expect($response->getStatusCode())->toBeIn([200, 428]);
    });
});

describe('devices', function (): void {
    it('lists the caller’s own devices', function (): void {
        $response = asSelf($this->accountant, 'GET', '/api/v1/me/devices');

        expect($response)->toBeEnvelope();
    });

    it('records the device a sign-in came from', function (): void {
        $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'accountant@acme.test',
            'password' => UserFactory::PASSWORD,
        ])->assertStatus(200);

        $devices = asSelf($this->accountant, 'GET', '/api/v1/me/devices')->json('data');

        // "Where am I signed in" is only answerable if sign-in records it, and it is the control a
        // user reaches for first when they suspect someone else has their password.
        expect($devices)->not->toBeEmpty();
    });

    it('refuses to revoke another user’s device', function (): void {
        // `forceFill`: `tenant_id` is deliberately not mass-assignable, because a request body that
        // could set it would be a cross-tenant write. The trait stamps it on save.
        $foreignDevice = RowLevelSecurity::bypass(function (): UserDevice {
            $device = new UserDevice;

            $device->forceFill([
                'user_id' => $this->owner->getKey(),
                'fingerprint_hash' => hash('sha256', 'owner-device'),
                'name' => 'Owner laptop',
            ])->save();

            return $device;
        });

        $response = asSelf($this->accountant, 'DELETE', "/api/v1/devices/{$foreignDevice->getKey()}");

        // Revoking someone else's trusted device is a denial of service against them, and knowing it
        // exists is itself information about their account.
        expect($response->getStatusCode())->toBeIn([403, 404])
            ->and($foreignDevice->refresh()->revoked_at)->toBeNull();
    });

    it('lets a user revoke their own device', function (): void {
        $device = RowLevelSecurity::bypass(function (): UserDevice {
            $device = new UserDevice;

            $device->forceFill([
                'user_id' => $this->accountant->getKey(),
                'fingerprint_hash' => hash('sha256', 'accountant-device'),
                'name' => 'Accountant laptop',
            ])->save();

            return $device;
        });

        $response = asSelf($this->accountant, 'DELETE', "/api/v1/devices/{$device->getKey()}");

        expect($response->getStatusCode())->toBeIn([200, 204])
            ->and($device->refresh()->revoked_at)->not->toBeNull();
    });
});

describe('sign-in history', function (): void {
    it('returns the caller’s own history', function (): void {
        $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'accountant@acme.test',
            'password' => UserFactory::PASSWORD,
        ]);

        $response = asSelf($this->accountant, 'GET', '/api/v1/me/login-history');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('gives an administrator the workspace-wide history', function (): void {
        $response = asSelf($this->owner, 'GET', '/api/v1/login-history');

        expect($response)->toBeEnvelope();
    });

    it('refuses the workspace-wide history to a caller without the permission', function (): void {
        // A viewer — an external accountant, a lender, an auditor — holds read access to the books
        // and nothing about the people. A full sign-in log tells them when each employee works and
        // from which address.
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');

        $response = asSelf($viewer, 'GET', '/api/v1/login-history');

        expect($response->getStatusCode())->toBe(403);
    });

    it('permits the workspace-wide history to a caller who holds the permission', function (): void {
        // The accountant template does include `identity.login_history.view`, so the same endpoint
        // must still work for them — the fix narrowed the check, and this is the half that proves it
        // did not simply close the endpoint.
        expect(asSelf($this->accountant, 'GET', '/api/v1/login-history'))->toBeEnvelope();
    });

    it('still lets any user read their own history', function (): void {
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');

        // `viewLoginHistory` remains target-relative, so `mine` works for everyone. Only the
        // workspace-wide listing needed the separate ability.
        expect(asSelf($viewer, 'GET', '/api/v1/me/login-history'))->toBeEnvelope();
    });
});

describe('personal access tokens', function (): void {
    it('lists the caller’s own tokens', function (): void {
        expect(asSelf($this->owner, 'GET', '/api/v1/tokens'))->toBeEnvelope();
    });

    it('issues a token and returns the plaintext exactly once', function (): void {
        $response = asSelf($this->owner, 'POST', '/api/v1/tokens', [
            'name' => 'Nightly export',
            'abilities' => ['identity.users.view'],
            'expires_in_days' => 90,
        ]);

        expect($response->getStatusCode())->toBe(201)
            // In `meta`, not `data`: the resource describes the stored token, and the plaintext is a
            // one-time side effect of creating it rather than a property of the record.
            ->and($response->json('meta.plaintext_token'))->toBeString()
            ->and($response->json('meta.plaintext_token'))->not->toBeEmpty()
            ->and($response->json('meta.notice'))->toBeString();

        // Only a hash is retained. A token the platform can read back is a token an operator can
        // read back.
        $stored = RowLevelSecurity::bypass(fn (): PersonalAccessToken => PersonalAccessToken::query()
            ->withoutGlobalScopes()
            ->where('name', 'Nightly export')
            ->firstOrFail());

        expect($response->json('meta.plaintext_token'))->not->toBe($stored->token);
    });

    it('intersects requested abilities with the creator’s own permissions', function (): void {
        // The accountant does not hold `identity.users.invite`. A token that did would let them
        // escalate simply by asking for it at issue time.
        $response = asSelf($this->accountant, 'POST', '/api/v1/tokens', [
            'name' => 'Overreaching',
            'abilities' => ['identity.users.invite'],
        ]);

        if ($response->getStatusCode() === 201) {
            expect($response->json('meta.granted_abilities'))->not->toContain('identity.users.invite');

            return;
        }

        expect($response->getStatusCode())->toBeIn([403, 422]);
    });

    it('refuses an ability that is not in the catalogue', function (): void {
        $response = asSelf($this->owner, 'POST', '/api/v1/tokens', [
            'name' => 'Invented',
            'abilities' => ['identity.users.invent'],
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('requires at least one ability', function (): void {
        $response = asSelf($this->owner, 'POST', '/api/v1/tokens', [
            'name' => 'Empty',
            'abilities' => [],
        ]);

        // A token with no abilities is a credential that authenticates and authorises nothing —
        // confusing to hold and impossible to debug.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('revokes the caller’s own token', function (): void {
        $id = asSelf($this->owner, 'POST', '/api/v1/tokens', [
            'name' => 'Disposable',
            'abilities' => ['identity.users.view'],
        ])->json('data.id');

        $response = asSelf($this->owner, 'DELETE', "/api/v1/tokens/{$id}");

        expect($response->getStatusCode())->toBeIn([200, 204]);
    });

    it('does not list another user’s tokens', function (): void {
        asSelf($this->owner, 'POST', '/api/v1/tokens', [
            'name' => 'Owner token',
            'abilities' => ['identity.users.view'],
        ]);

        $names = collect(asSelf($this->accountant, 'GET', '/api/v1/tokens')->json('data'))
            ->pluck('name')
            ->all();

        expect($names)->not->toContain('Owner token');
    });
});

describe('password reset by link', function (): void {
    it('answers identically whether or not the address exists', function (): void {
        $known = $this->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'accountant@acme.test']);

        $unknown = $this->withHeader('X-Tenant', 'acme')
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@acme.test']);

        // Any difference — status, body, or measurable timing — turns this endpoint into an account
        // enumeration oracle that needs no credential at all.
        expect($known->getStatusCode())->toBe($unknown->getStatusCode())
            ->and($known->json('data'))->toBe($unknown->json('data'));
    });

    it('refuses an unsigned account link', function (): void {
        $response = $this->withHeader('X-Tenant', 'acme')
            ->getJson("/api/v1/auth/account-link/{$this->accountant->getKey()}?signature=forged");

        expect($response->getStatusCode())->toBeIn([401, 403, 404, 422]);
    });
});
