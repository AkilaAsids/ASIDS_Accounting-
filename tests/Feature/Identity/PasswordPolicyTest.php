<?php

declare(strict_types=1);

use Asids\Core\Identity\Application\Services\PasswordPolicyService;
use Asids\Core\Identity\Domain\Models\PasswordHistory;
use Database\Factories\UserFactory;

/**
 * The password reuse and expiry policy.
 *
 * A feature test rather than a unit one on purpose: the reuse check bcrypt-compares against archived
 * hashes, so the archive and the pruning are half the behaviour. A test with a mocked hasher would
 * assert the shape of the code rather than the property — that a password genuinely cannot be
 * rotated back to a recent one.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->user = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');
    $this->policy = app(PasswordPolicyService::class);
});

describe('reuse', function (): void {
    it('archives the outgoing hash before replacing it', function (): void {
        $original = $this->user->password;

        $this->policy->set($this->user, 'FirstReplacement#2026');

        // Archived *before* the swap. Doing it after would store the new hash and let the previous
        // password be reused immediately, which is the entire failure this policy exists to prevent.
        expect(PasswordHistory::query()->where('user_id', $this->user->getKey())->pluck('password_hash')->all())
            ->toContain($original);
    });

    it('refuses the password currently in force', function (): void {
        $exception = catchPlatformException(
            fn () => $this->policy->set($this->user, UserFactory::PASSWORD),
        );

        // "You cannot reuse your last five" has to include the one in force, or the policy permits
        // the single most likely reuse.
        expect($exception->problemCode())->toBe('password-previously-used');
    });

    it('refuses a password used within the configured history length', function (): void {
        config(['asids.auth.password.history' => 3]);

        $this->policy->set($this->user, 'Rotation#One2026');
        $this->policy->set($this->user, 'Rotation#Two2026');

        $exception = catchPlatformException(fn () => $this->policy->set($this->user, 'Rotation#One2026'));

        expect($exception->problemCode())->toBe('password-previously-used');
    });

    it('permits a password that has fallen out of the history window', function (): void {
        config(['asids.auth.password.history' => 2]);

        $target = 'Rotation#Target2026';

        // A minute between rotations, deliberately. `password_changed_at` and the archive's
        // `created_at` are written without sub-second precision, so several rotations inside the same
        // second give the "latest N" ordering nothing to sort by and the pruning becomes arbitrary.
        // Rotating on a realistic timescale is both closer to life and not flaky.
        foreach ([$target, 'Rotation#Two2026', 'Rotation#Three2026', 'Rotation#Four2026'] as $password) {
            $this->policy->set($this->user, $password);
            $this->travel(1)->minutes();
        }

        // Bounded on purpose. An unbounded history means an account that has rotated fifty times
        // pays fifty bcrypt comparisons on every change, and bcrypt is deliberately slow. With a
        // window of two, the target is now the third-oldest and has aged out.
        $this->policy->set($this->user, $target);

        expect($this->policy->matchesCurrent($this->user->refresh(), $target))->toBeTrue();
    });

    it('keeps the archive bounded to the configured length', function (): void {
        config(['asids.auth.password.history' => 2]);

        foreach (['Rotation#One2026', 'Rotation#Two2026', 'Rotation#Three2026', 'Rotation#Four2026'] as $password) {
            $this->policy->set($this->user, $password);
            $this->travel(1)->minutes();
        }

        expect(PasswordHistory::query()->where('user_id', $this->user->getKey())->count())
            ->toBeLessThanOrEqual(2);
    });

    it('applies no reuse check when the policy is disabled', function (): void {
        config(['asids.auth.password.history' => 0]);

        // Zero means off, and it has to mean off rather than "check nothing but still refuse the
        // current one" — a deployment that disables the policy should behave as though it is absent.
        $this->policy->set($this->user, 'Reusable#Password2026');
        $this->policy->set($this->user, 'Reusable#Password2026');

        expect($this->policy->matchesCurrent($this->user->refresh(), 'Reusable#Password2026'))->toBeTrue();
    });

    it('does not consult another user’s history', function (): void {
        $other = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');

        $this->policy->set($this->user, 'Shared#Password2026');

        // Two people may legitimately choose the same password, and refusing the second would tell
        // the second person what the first one's password is.
        $this->policy->set($other, 'Shared#Password2026');

        expect($this->policy->matchesCurrent($other->refresh(), 'Shared#Password2026'))->toBeTrue();
    });
});

describe('storage', function (): void {
    it('stores a hash, never the plaintext', function (): void {
        $this->policy->set($this->user, 'Stored#Password2026');

        $stored = (string) $this->user->refresh()->password;

        expect($stored)->not->toBe('Stored#Password2026')
            ->and($stored)->toStartWith('$2y$')
            ->and($this->policy->matchesCurrent($this->user, 'Stored#Password2026'))->toBeTrue();
    });

    it('records when the password changed, so expiry can be computed', function (): void {
        $this->user->forceFill(['password_changed_at' => now()->subDays(100)])->save();

        $this->policy->set($this->user, 'Fresh#Password2026');

        expect($this->user->refresh()->password_changed_at?->isToday())->toBeTrue();
    });

    it('clears the forced-change flag by default', function (): void {
        $this->user->forceFill(['must_change_password' => true])->save();

        $this->policy->set($this->user, 'Chosen#Password2026');

        // The user has now chosen their own password, so continuing to force a change would trap
        // them in the change-password screen.
        expect($this->user->refresh()->must_change_password)->toBeFalse();
    });

    it('can require another change immediately, for an administrative reset', function (): void {
        $this->policy->set($this->user, 'Temporary#Password2026', mustChangeAgain: true);

        // An administrator who sets a password knows it. Forcing a change on first use is what keeps
        // that from being a durable shared credential.
        expect($this->user->refresh()->must_change_password)->toBeTrue();
    });
});

describe('expiry', function (): void {
    it('reports a password older than the configured age as expired', function (): void {
        config(['asids.auth.password.expires_after_days' => 90]);

        $this->user->forceFill(['password_changed_at' => now()->subDays(91)])->save();

        expect($this->user->refresh()->passwordHasExpired())->toBeTrue();
    });

    it('reports a recent password as current', function (): void {
        config(['asids.auth.password.expires_after_days' => 90]);

        $this->user->forceFill(['password_changed_at' => now()->subDays(10)])->save();

        expect($this->user->refresh()->passwordHasExpired())->toBeFalse();
    });

    it('treats a forced change as an expiry regardless of age', function (): void {
        config(['asids.auth.password.expires_after_days' => 90]);

        $this->user->forceFill(['password_changed_at' => now(), 'must_change_password' => true])->save();

        // Both conditions route the user to the same screen, so they resolve to the same question.
        expect($this->user->refresh()->passwordHasExpired())->toBeTrue();
    });

    it('never expires when expiry is disabled', function (): void {
        config(['asids.auth.password.expires_after_days' => 0]);

        $this->user->forceFill(['password_changed_at' => now()->subYears(5)])->save();

        expect($this->user->refresh()->passwordHasExpired())->toBeFalse();
    });

    it('treats an unknown change date as expired when expiry is on', function (): void {
        config(['asids.auth.password.expires_after_days' => 90]);

        $this->user->forceFill(['password_changed_at' => null])->save();

        // Fail closed. A row with no recorded change date is either a data migration or an
        // invitation that never completed, and neither should be granted an indefinite password.
        expect($this->user->refresh()->passwordHasExpired())->toBeTrue();
    });
});
