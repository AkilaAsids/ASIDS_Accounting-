<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Enums\LoginOutcome;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\LoginHistory;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Database\Factories\UserFactory;

/**
 * Authentication, lockout, and the enumeration defences.
 *
 * The property under test throughout: a caller who does not already hold a credential must not
 * be able to learn *anything* — not whether an address exists, not whether an account is
 * suspended, not which workspace someone belongs to.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');
    $this->withinTenant($this->acme['tenant']);

    $this->user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', [
        'email' => 'kumari@acme.test',
    ]);
});

function signIn(array $payload): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/login', $payload);
}

describe('successful sign-in', function (): void {
    it('signs in and returns the whole session in one call', function (): void {
        $response = signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        expect($response)->toBeEnvelope();

        // The shell cannot render until all of these arrive, so they come together rather than
        // as four requests.
        $response->assertJsonPath('data.authenticated', true)
            ->assertJsonStructure(['data' => ['user', 'permissions', 'workspace', 'companies', 'requires']]);
    });

    it('never returns the password hash or the two factor secret', function (): void {
        $response = signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        expect($response)->toNotLeak('password', 'two_factor_secret', 'remember_token');
    });

    it('records the sign-in with provenance', function (): void {
        signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        $entry = LoginHistory::query()->where('user_id', $this->user->getKey())->firstOrFail();

        expect($entry->outcome)->toBe(LoginOutcome::Succeeded)
            ->and($entry->ip_address)->not->toBeEmpty();
    });

    it('clears the failure counter on success', function (): void {
        $this->user->forceFill(['failed_login_attempts' => 3])->save();

        signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        expect($this->user->fresh()->failed_login_attempts)->toBe(0);
    });
});

describe('enumeration defences', function (): void {
    it('answers identically for an unknown address and a wrong password', function (): void {
        $unknown = signIn(['email' => 'nobody@acme.test', 'password' => 'whatever-long-enough']);
        $wrong = signIn(['email' => 'kumari@acme.test', 'password' => 'definitely-not-it']);

        // Byte-identical apart from the request id. Any difference — status, wording, an extra
        // field — is an oracle telling an attacker which addresses hold accounts.
        expect($unknown->getStatusCode())->toBe($wrong->getStatusCode())
            ->and($unknown->json('type'))->toBe($wrong->json('type'))
            ->and($unknown->json('detail'))->toBe($wrong->json('detail'));

        expect($unknown)->toBeProblem('authentication-failed', 401);
    });

    it('records a failed attempt against an address that does not exist', function (): void {
        signIn(['email' => 'nobody@acme.test', 'password' => 'whatever-long-enough']);

        $entry = LoginHistory::query()->where('email_attempted', 'nobody@acme.test')->firstOrFail();

        // Discarding these would blind credential-stuffing detection, which is precisely the
        // pattern of many failures against many non-existent addresses.
        expect($entry->user_id)->toBeNull()
            ->and($entry->outcome)->toBe(LoginOutcome::Failed)
            ->and($entry->failure_reason)->toBe('unknown_account');
    });

    it('does not authenticate a valid credential from another workspace', function (): void {
        $other = $this->createUserWithRole($this->globex['tenant'], 'bookkeeper', [
            'email' => 'ravi@globex.test',
        ]);

        // Correct password, wrong workspace. The user lookup is tenant-scoped, so this is
        // indistinguishable from a non-existent account.
        $response = signIn(['email' => $other->email, 'password' => UserFactory::PASSWORD]);

        expect($response)->toBeProblem('authentication-failed', 401);
    });

    it('reveals account status only after the password is verified', function (): void {
        $this->user->forceFill(['status' => UserStatus::Suspended])->save();

        $wrongPassword = signIn(['email' => 'kumari@acme.test', 'password' => 'wrong-but-long-enough']);
        $rightPassword = signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        // Without the password: nothing. With it: the real reason, which discloses nothing to
        // someone who does not already hold the credential.
        expect($wrongPassword)->toBeProblem('authentication-failed', 401);
        expect($rightPassword)->toBeProblem('account-inactive', 403);
    });
});

describe('lockout', function (): void {
    it('locks the account after the configured number of failures', function (): void {
        $max = (int) config('asids.auth.lockout.max_attempts');

        for ($attempt = 0; $attempt < $max; $attempt++) {
            signIn(['email' => 'kumari@acme.test', 'password' => 'wrong-but-long-enough']);
        }

        expect($this->user->fresh()->isLocked())->toBeTrue();
    });

    it('discloses how long the lockout lasts', function (): void {
        $this->user->forceFill(['locked_until' => now()->addMinutes(15)])->save();

        $response = signIn(['email' => 'kumari@acme.test', 'password' => UserFactory::PASSWORD]);

        // Unlike a failed password, the remaining time *is* disclosed: withholding it produces
        // support tickets and teaches the user nothing, and an attacker who caused the lockout
        // already knows it happened.
        expect($response)->toBeProblem('account-locked', 423);
        expect($response->json('retry_after_seconds'))->toBeGreaterThan(0);
    });

    it('does not count a pending second factor toward lockout', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', [
            'email' => 'twofa@acme.test',
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);

        signIn(['email' => 'twofa@acme.test', 'password' => UserFactory::PASSWORD]);

        // The password was correct. Counting this would lock out every user who takes a moment
        // to open their authenticator app.
        expect($user->fresh()->failed_login_attempts)->toBe(0);
    });
});

describe('two factor challenge', function (): void {
    it('issues a challenge instead of a session when a second factor is enrolled', function (): void {
        $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', [
            'email' => 'twofa@acme.test',
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = signIn(['email' => 'twofa@acme.test', 'password' => UserFactory::PASSWORD]);

        // 200, not 401: a challenge is the successful outcome of a correct password, and
        // returning an error status would make the client's error path fight its happy path.
        expect($response)->toBeEnvelope(200);
        $response->assertJsonPath('data.two_factor_required', true);

        expect($response->json('data.challenge'))->toBeString()->toHaveLength(64);
        expect($this->app['auth']->guard('web')->check())->toBeFalse();
    });

    it('records that a second factor was required', function (): void {
        $user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', [
            'email' => 'twofa@acme.test',
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ]);

        signIn(['email' => 'twofa@acme.test', 'password' => UserFactory::PASSWORD]);

        expect(LoginHistory::query()->where('user_id', $user->getKey())->value('outcome'))
            ->toBe(LoginOutcome::TwoFactorRequired->value);
    });

    it('rejects an expired or unknown challenge', function (): void {
        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge' => str_repeat('a', 64),
            'code' => '123456',
        ]);

        expect($response)->toBeProblem('two-factor-challenge-expired', 401);
    });
});

describe('forgotten password', function (): void {
    it('answers identically whether or not the address exists', function (): void {
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'kumari@acme.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@acme.test']);

        expect($known->getStatusCode())->toBe(202)
            ->and($unknown->getStatusCode())->toBe(202)
            ->and($known->json('data.message'))->toBe($unknown->json('data.message'));
    });
});

describe('invited accounts', function (): void {
    it('cannot sign in before the invitation is accepted', function (): void {
        RowLevelSecurity::bypass(fn () => User::factory()->invited()->create([
            'tenant_id' => $this->acme['tenant']->getKey(),
            'email' => 'pending@acme.test',
        ]));

        // A null password means no credential exists that could authenticate — which is also
        // what makes the invitation link single-use.
        $response = signIn(['email' => 'pending@acme.test', 'password' => UserFactory::PASSWORD]);

        expect($response)->toBeProblem('authentication-failed', 401);
    });
});
