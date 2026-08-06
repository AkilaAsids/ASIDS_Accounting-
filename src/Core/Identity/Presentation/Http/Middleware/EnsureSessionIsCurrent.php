<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Middleware;

use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates sessions that were invalidated server-side, and sessions that have gone idle.
 *
 * WHY AN EPOCH
 * ------------
 * Sessions live in Redis in production, where they cannot be enumerated by user — so
 * "sign out everywhere" cannot simply delete rows. Instead a per-user epoch is bumped in the
 * cache when access is withdrawn (password change, suspension, deactivation, explicit
 * sign-out-everywhere). Each request compares the epoch stamped into the session against the
 * stored one, and a mismatch ends the session. One cache read per request buys immediate,
 * driver-independent revocation.
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

        $session = $request->session();
        $currentEpoch = cache()->get(UserService::sessionEpochKey($user));

        if (is_string($currentEpoch)) {
            $sessionEpoch = $session->get(self::SESSION_EPOCH);

            if (! is_string($sessionEpoch)) {
                // A session predating the first revocation. Adopt the current epoch rather
                // than terminating it, so introducing this middleware does not sign out the
                // entire customer base on deploy.
                $session->put(self::SESSION_EPOCH, $currentEpoch);
            } elseif (! hash_equals($currentEpoch, $sessionEpoch)) {
                return $this->endSession($request);
            }
        }

        if ($this->hasGoneIdle($user)) {
            return $this->endSession($request);
        }

        return $next($request);
    }

    /**
     * Idle timeout measured from the user's own last activity rather than from the session
     * cookie's lifetime, so a tab left open on a dashboard that polls does not keep a
     * finance system authenticated indefinitely.
     */
    private function hasGoneIdle(User $user): bool
    {
        $minutes = (int) config('asids.auth.session.idle_timeout', 0);

        if ($minutes <= 0 || $user->last_activity_at === null) {
            return false;
        }

        return $user->last_activity_at->addMinutes($minutes)->isPast();
    }

    /**
     * NOT named `terminate()`. Laravel's Kernel calls `terminate($request, $response)` on any
     * middleware that declares one, and `method_exists()` sees private methods — so a private
     * `terminate()` here is invoked by the framework with the wrong signature from the wrong
     * scope, fatally, on *every* request that passes through this middleware.
     */
    private function endSession(Request $request): never
    {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new AuthenticationException('Your session has ended. Please sign in again.');
    }
}
