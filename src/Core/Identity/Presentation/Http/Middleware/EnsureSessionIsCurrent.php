<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Middleware;

use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Terminates sessions that were revoked server-side, and sessions that have gone idle.
 *
 * WHY AN EPOCH
 * ------------
 * Sessions live in Redis in production, where they cannot be enumerated by user — so
 * "sign out everywhere" cannot simply delete rows. Instead a per-user epoch is bumped in the
 * cache when access is withdrawn (password change, suspension, deactivation, explicit
 * sign-out-everywhere). Each request compares the epoch stamped into the session against the
 * stored one, and a mismatch ends the session.
 *
 * THIS MIDDLEWARE FAILS OPEN, DELIBERATELY
 * ----------------------------------------
 * An earlier version ended the session whenever it could not satisfy itself that the session
 * was current. That inverted the risk. This check exists to revoke access that has *already*
 * been withdrawn by an explicit administrative act; it is not what decides whether a user is
 * authenticated — `auth:sanctum` has already done that, upstream. The two failure modes are
 * therefore not symmetric:
 *
 *   Failing closed on an ambiguous signal locks every user out of the product. That is a total
 *   outage, and it is exactly what happened: every request after sign-in returned 401 while
 *   authentication itself was working perfectly.
 *
 *   Failing open leaves a session alive for at most the remainder of its lifetime, in a system
 *   where account status is re-read from the database on every authorisation check anyway —
 *   `Gate::before` denies an inactive account outright.
 *
 * A session is therefore ended only on a **positive, unambiguous** signal: two epochs that both
 * exist and differ, or an activity timestamp that definitively predates the idle window.
 * Anything else — a missing epoch, a missing timestamp, an unexpected exception — logs and
 * continues.
 */
final class EnsureSessionIsCurrent
{
    public const string SESSION_EPOCH = 'asids_session_epoch';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        try {
            if ($this->wasRevoked($request, $user) || $this->hasGoneIdle($user)) {
                return $this->endSession($request);
            }
        } catch (AuthenticationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Cache unavailable, malformed session, anything unforeseen. Log loudly and let the
            // request through: an authenticated user must not be locked out by a fault in a
            // supplementary check.
            Log::warning('Session currency check failed; allowing the request.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $next($request);
    }

    /**
     * True only when the stored epoch and the session's epoch both exist and disagree.
     *
     * A session with no epoch stamped into it adopts the current one rather than being treated
     * as suspect — that is what lets this middleware be introduced without signing out every
     * existing session on deploy.
     */
    private function wasRevoked(Request $request, User $user): bool
    {
        $currentEpoch = cache()->get(UserService::sessionEpochKey($user));

        if (! is_string($currentEpoch) || $currentEpoch === '') {
            // No revocation has ever been recorded for this user.
            return false;
        }

        $sessionEpoch = $request->session()->get(self::SESSION_EPOCH);

        if (! is_string($sessionEpoch) || $sessionEpoch === '') {
            $request->session()->put(self::SESSION_EPOCH, $currentEpoch);

            return false;
        }

        return ! hash_equals($currentEpoch, $sessionEpoch);
    }

    /**
     * Idle timeout measured from the user's own last activity rather than the cookie's lifetime,
     * so a tab left open on a polling dashboard does not keep a finance system authenticated
     * indefinitely.
     *
     * A null timestamp means "never recorded", which is not evidence of idleness.
     */
    private function hasGoneIdle(User $user): bool
    {
        $minutes = (int) config('asids.auth.session.idle_timeout', 0);

        if ($minutes <= 0 || $user->last_activity_at === null) {
            return false;
        }

        return $user->last_activity_at->addMinutes($minutes)->isPast();
    }

    private function endSession(Request $request): never
    {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new AuthenticationException('Your session has ended. Please sign in again.');
    }
}
