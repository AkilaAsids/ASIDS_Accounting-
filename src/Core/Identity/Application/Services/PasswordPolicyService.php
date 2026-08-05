<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Exceptions\PasswordPreviouslyUsed;
use Asids\Core\Identity\Domain\Models\PasswordHistory;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;

/**
 * Owns everything about setting a password.
 *
 * Centralised because there are four entry points — invitation acceptance,
 * self-service change, forgotten-password reset, administrative reset — and every
 * one of them must apply reuse prevention, record the rotation timestamp, prune the
 * history and (except on invitation) invalidate other sessions. Four
 * implementations of that would drift, and the one that drifted would be the one
 * that skipped a control.
 */
final readonly class PasswordPolicyService
{
    public function __construct(private Hasher $hasher) {}

    /**
     * Set a new password, enforcing the reuse policy.
     *
     * The complexity rules themselves are enforced at the validation layer by
     * `Password::defaults()`, which is configured once in PlatformServiceProvider —
     * putting them here as well would create two sources of truth.
     */
    public function set(User $user, string $plaintext, bool $mustChangeAgain = false): void
    {
        $this->assertNotReused($user, $plaintext);

        DB::transaction(function () use ($user, $plaintext, $mustChangeAgain): void {
            // The outgoing hash is archived before it is replaced; doing it after
            // would archive the new one.
            if ($user->password !== null) {
                PasswordHistory::query()->create([
                    'user_id' => $user->getKey(),
                    'password_hash' => $user->password,
                ]);
            }

            // Assigning the plaintext is correct: the `hashed` cast on the model
            // hashes it on the way to the database.
            $user->password = $plaintext;
            $user->password_changed_at = now();
            $user->must_change_password = $mustChangeAgain;
            $user->save();

            $this->pruneHistory($user);
        });
    }

    public function matchesCurrent(User $user, string $plaintext): bool
    {
        return $user->password !== null
            && $this->hasher->check($plaintext, $user->password);
    }

    private function assertNotReused(User $user, string $plaintext): void
    {
        $historyLength = (int) config('asids.auth.password.history');

        if ($historyLength <= 0) {
            return;
        }

        // The current password counts as part of the history: "you cannot reuse
        // your last five" must include the one in force.
        if ($this->matchesCurrent($user, $plaintext)) {
            throw PasswordPreviouslyUsed::withinLast($historyLength);
        }

        /** @var list<string> $hashes */
        $hashes = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest()
            ->limit($historyLength)
            ->pluck('password_hash')
            ->all();

        foreach ($hashes as $hash) {
            if ($this->hasher->check($plaintext, $hash)) {
                throw PasswordPreviouslyUsed::withinLast($historyLength);
            }
        }
    }

    /**
     * Keeps the history bounded. Without this, a long-lived account accumulates a
     * row per rotation forever, and every password change pays to bcrypt-compare
     * all of them.
     */
    private function pruneHistory(User $user): void
    {
        $keep = (int) config('asids.auth.password.history');

        /** @var list<string> $retain */
        $retain = PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->latest()
            ->limit(max($keep, 1))
            ->pluck('id')
            ->all();

        PasswordHistory::query()
            ->where('user_id', $user->getKey())
            ->whereNotIn('id', $retain)
            ->delete();
    }
}
