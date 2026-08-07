<?php

declare(strict_types=1);

use Asids\Core\Identity\Application\Services\TwoFactorService;
use Asids\Core\Identity\Domain\Models\TwoFactorRecoveryCode;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Presentation\Http\Middleware\EnsureTwoFactorIsConfirmed;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * The full two-factor lifecycle, and the two credentials that are deliberately not
 * interchangeable.
 *
 * A TOTP code proves possession of the device now. A recovery code proves you once wrote something
 * down. They are accepted in different places on purpose: a recovery code signs you in, but only a
 * TOTP code opens the step-up window, because accepting a recovery code there would let one
 * intercepted code authorise an ownership transfer.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->totp = app(Google2FA::class);
    $this->service = app(TwoFactorService::class);

    $this->user = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'twofa@acme.test',
    ]);
});

function asTwoFactorUser(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

/**
 * As above, but with the step-up window already open.
 *
 * Opening it by calling `confirm-session` would consume the current timestep, and the endpoint under
 * test then has no valid code left to present — the replay guard refuses the same digits twice, which
 * is asserted at the end of this file. Travelling forward does not help either: `Carbon::travel()`
 * moves Carbon's clock, not PHP's `time()`, which is what google2fa reads. Seeding the session key is
 * both simpler and closer to what a client holds after it has answered a 428 prompt.
 */
function asSteppedUpUser(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withSession([EnsureTwoFactorIsConfirmed::SESSION_KEY => now()->getTimestamp()])
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

/**
 * Enrols and confirms, returning the shared secret so a test can compute valid codes.
 */
function enrolTwoFactor(User $user): string
{
    $secret = asTwoFactorUser($user, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

    asTwoFactorUser($user, 'POST', '/api/v1/auth/two-factor/confirm', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertSuccessful();

    return (string) $secret;
}

describe('signing in with a second factor', function (): void {
    it('issues a challenge instead of a session', function (): void {
        enrolTwoFactor($this->user);

        $response = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'twofa@acme.test',
            'password' => UserFactory::PASSWORD,
        ]);

        // Asserted on the payload, not on the guard: `enrolTwoFactor` used `actingAs`, so the test's
        // own container still holds an authenticated user. What matters is that this response carries
        // a challenge and *not* a session — the shell renders off `data.authenticated`.
        expect($response->json('data.challenge'))->toBeString()
            ->and($response->json('data.authenticated'))->toBeNull();
    });

    it('completes the sign-in with a valid TOTP code', function (): void {
        $secret = enrolTwoFactor($this->user);

        $challenge = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'twofa@acme.test',
            'password' => UserFactory::PASSWORD,
        ])->json('data.challenge');

        $response = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $this->totp->getCurrentOtp($secret),
        ]);

        expect($response->json('data.authenticated'))->toBeTrue();
    });

    it('completes the sign-in with a recovery code', function (): void {
        $secret = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

        $codes = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->json('data.recovery_codes');

        $challenge = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
            'email' => 'twofa@acme.test',
            'password' => UserFactory::PASSWORD,
        ])->json('data.challenge');

        $response = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge' => $challenge,
            'code' => $codes[0],
        ]);

        // The credential for someone whose phone is lost or wiped. Without it, losing a device means
        // losing the workspace.
        expect($response->json('data.authenticated'))->toBeTrue();
    });

    it('consumes a recovery code so it cannot be reused', function (): void {
        $secret = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

        $codes = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->json('data.recovery_codes');

        $signInWith = function (string $code): TestResponse {
            $challenge = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/login', [
                'email' => 'twofa@acme.test',
                'password' => UserFactory::PASSWORD,
            ])->json('data.challenge');

            return $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/two-factor-challenge', [
                'challenge' => $challenge,
                'code' => $code,
            ]);
        };

        $signInWith($codes[0])->assertSuccessful();

        // Single use, enforced by a conditional UPDATE rather than select-then-save: two concurrent
        // requests presenting the same code must not both succeed.
        expect($signInWith($codes[0])->getStatusCode())->toBeIn([401, 422]);
    });

    it('rejects an unknown challenge', function (): void {
        $response = $this->withHeader('X-Tenant', 'acme')->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge' => str_repeat('a', 64),
            'code' => '123456',
        ]);

        expect($response->getStatusCode())->toBeIn([401, 422]);
    });
});

describe('the step-up window', function (): void {
    it('opens with a TOTP code', function (): void {
        $secret = enrolTwoFactor($this->user);

        $response = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm-session', [
            'code' => $this->totp->getCurrentOtp($secret),
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.confirmed'))->toBeTrue()
            ->and($response->json('data.expires_in'))->toBeInt();
    });

    it('refuses to open with a recovery code', function (): void {
        $secret = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

        $codes = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->json('data.recovery_codes');

        $response = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm-session', [
            'code' => $codes[0],
        ]);

        // The distinction that matters. A recovery code is written on paper and may have been
        // photographed; accepting it for step-up would let one intercepted code authorise an
        // ownership transfer.
        expect($response->getStatusCode())->toBeIn([401, 422]);
    });

    it('refuses to open for a user with no second factor', function (): void {
        $response = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm-session', [
            'code' => '123456',
        ]);

        expect($response)->toBeProblem('two-factor-not-enabled');
    });
});

describe('turning it off', function (): void {
    it('requires a fresh code', function (): void {
        enrolTwoFactor($this->user);

        $response = asTwoFactorUser($this->user, 'DELETE', '/api/v1/auth/two-factor', ['code' => '000000']);

        // A hijacked session must not be able to remove the control that would have stopped it.
        expect($response->getStatusCode())->toBeIn([401, 422, 428])
            ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('turns it off with a valid code and clears the recovery codes', function (): void {
        $secret = enrolTwoFactor($this->user);

        // Step-up first: the route is protected and the user now has a second factor.
        $response = asSteppedUpUser($this->user, 'DELETE', '/api/v1/auth/two-factor', [
            'code' => $this->totp->getCurrentOtp($secret),
        ]);

        expect($response)->toBeEnvelope();

        $disabled = $this->user->refresh();

        // Codes are useless without a secret, and leaving them would let a re-enrolment silently
        // inherit codes the user had already printed and lost.
        expect($disabled->hasTwoFactorEnabled())->toBeFalse()
            ->and(RowLevelSecurity::bypass(fn (): int => TwoFactorRecoveryCode::query()
                ->where('user_id', $disabled->getKey())
                ->count()))->toBe(0);
    });

    it('refuses to turn it off when the workspace mandates it', function (): void {
        $secret = enrolTwoFactor($this->user);

        config(['asids.auth.two_factor.enforced' => true]);

        $response = asSteppedUpUser($this->user, 'DELETE', '/api/v1/auth/two-factor', [
            'code' => $this->totp->getCurrentOtp($secret),
        ]);

        expect($response)->toBeProblem('two-factor-mandatory')
            ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
    });
});

describe('recovery codes', function (): void {
    it('replaces the previous set', function (): void {
        $secret = enrolTwoFactor($this->user);

        $original = RowLevelSecurity::bypass(fn (): array => TwoFactorRecoveryCode::query()
            ->where('user_id', $this->user->getKey())
            ->pluck('code_hash')
            ->all());

        $response = asSteppedUpUser($this->user, 'POST', '/api/v1/auth/two-factor/recovery-codes', [
            'code' => $this->totp->getCurrentOtp($secret),
        ]);

        expect($response)->toBeEnvelope();

        $replaced = RowLevelSecurity::bypass(fn (): array => TwoFactorRecoveryCode::query()
            ->where('user_id', $this->user->getKey())
            ->pluck('code_hash')
            ->all());

        // Regenerating has to invalidate the old set. Otherwise a user who regenerates because they
        // fear the old list was seen has achieved nothing.
        expect(array_intersect($original, $replaced))->toBe([]);
    });

    it('reports how many remain', function (): void {
        enrolTwoFactor($this->user);

        $status = asTwoFactorUser($this->user, 'GET', '/api/v1/auth/two-factor')->json('data');

        // Surfaced so the interface can warn before the user runs out — at which point losing their
        // device means losing the workspace.
        expect($status['unused_recovery_codes'])->toBeGreaterThan(0);
    });

    it('stores only hashes', function (): void {
        $secret = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/enrol')->json('data.secret');

        $codes = asTwoFactorUser($this->user, 'POST', '/api/v1/auth/two-factor/confirm', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->json('data.recovery_codes');

        $hashes = RowLevelSecurity::bypass(fn (): array => TwoFactorRecoveryCode::query()
            ->where('user_id', $this->user->getKey())
            ->pluck('code_hash')
            ->all());

        // A recovery code the platform can read back is one an operator can read back.
        expect($hashes)->not->toContain($codes[0]);
    });
});

describe('replay resistance', function (): void {
    it('refuses a TOTP code that has already been accepted', function (): void {
        $secret = enrolTwoFactor($this->user);

        $code = $this->totp->getCurrentOtp($secret);

        expect($this->service->verifyTotp($this->user->refresh(), $code))->toBeTrue();

        // `verifyKeyNewer` refuses a code from a timestep at or before the last accepted one, which
        // is what actually stops replay of a code sniffed seconds earlier — the whole reason for
        // preferring it over `verifyKey`.
        expect($this->service->verifyTotp($this->user->refresh(), $code))->toBeFalse();
    });

    it('ignores non-digits in a submitted code', function (): void {
        $secret = enrolTwoFactor($this->user);

        $code = $this->totp->getCurrentOtp($secret);
        $spaced = implode(' ', str_split($code, 3));

        // Authenticator apps display codes in groups, and users paste what they see.
        expect($this->service->verifyTotp($this->user->refresh(), $spaced))->toBeTrue();
    });

    it('refuses an empty code without consulting the secret', function (): void {
        enrolTwoFactor($this->user);

        expect($this->service->verifyTotp($this->user->refresh(), '   '))->toBeFalse();
    });
});
