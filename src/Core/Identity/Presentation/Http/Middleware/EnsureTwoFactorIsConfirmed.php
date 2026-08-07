<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Middleware;

use Asids\Core\Identity\Domain\Exceptions\TwoFactorConfirmationRequired;
use Asids\Core\Identity\Domain\Exceptions\TwoFactorEnrolmentRequired;
use Asids\Core\Identity\Domain\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication, and workspace-wide 2FA enforcement.
 *
 * Two distinct jobs, both keyed on the same question — "has this person proved possession of
 * their second factor recently?":
 *
 *   1. On individual sensitive routes (ownership transfer, clearing someone else's second
 *      factor, issuing an API token), a hijacked session must not be sufficient. The user is
 *      asked to re-enter a code, and the proof is valid for a short window.
 *
 *   2. When the workspace mandates 2FA, a user who has not enrolled is confined to the
 *      enrolment and sign-out endpoints. That is enforced here rather than at sign-in,
 *      because a user who cannot sign in cannot enrol.
 */
final class EnsureTwoFactorIsConfirmed
{
    public const string SESSION_KEY = 'asids_two_factor_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            // Enforcement mode: no second factor at all, and the workspace requires one.
            if ((bool) config('asids.auth.two_factor.enforced')) {
                throw new TwoFactorEnrolmentRequired;
            }

            // Without enforcement there is nothing to step up to. Refusing here would make
            // sensitive actions unreachable for a user who has chosen not to enrol, so the
            // route's own permission check remains the control.
            return $next($request);
        }

        if (! $this->confirmedRecently($request)) {
            throw new TwoFactorConfirmationRequired;
        }

        return $next($request);
    }

    private function confirmedRecently(Request $request): bool
    {
        $ttl = (int) config('asids.auth.two_factor.confirmation_ttl', 15);

        // Token-authenticated integrations have no session and cannot be challenged
        // interactively. They are held to the token's abilities instead, which is why a token
        // can never be granted a step-up-protected ability.
        if (! $request->hasSession()) {
            return false;
        }

        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        if (! is_int($confirmedAt)) {
            return false;
        }

        return (now()->getTimestamp() - $confirmedAt) <= $ttl * 60;
    }
}
