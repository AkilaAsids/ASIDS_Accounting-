<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Exceptions\AccountLinkInvalid;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Issues and verifies the two links that let a user act before they can authenticate:
 * an invitation, and a password reset.
 *
 * WHY NOT LARAVEL'S TOKEN TABLE
 * -----------------------------
 * `password_reset_tokens` is keyed on the e-mail address, and in ASIDS an address is
 * unique only within a tenant — the same external accountant may hold accounts at several
 * customers. A shared table would let a reset requested for workspace A be redeemed in
 * workspace B. This service keys on the user's UUID, which is globally unique.
 *
 * SINGLE USE WITHOUT STORING ANYTHING
 * -----------------------------------
 * The signature covers a fingerprint derived from the user's *current* credential state.
 * Setting a password changes that state, which invalidates every outstanding link for the
 * user — so a reset link stops working the moment it is used, and an invitation link dies
 * once accepted, with no token table and no cleanup job. It also means a user who resets
 * their password kills any link an attacker may have intercepted earlier.
 */
final readonly class AccountLinkService
{
    public const string PURPOSE_INVITATION = 'invitation';

    public const string PURPOSE_PASSWORD_RESET = 'password-reset';

    /**
     * Seven days: an invitation has to survive a weekend and a holiday, or a new hire's
     * first interaction with the product is a dead link.
     */
    private const int INVITATION_TTL_MINUTES = 60 * 24 * 7;

    /**
     * Sixty minutes. Short, because a reset link is a full credential.
     */
    private const int RESET_TTL_MINUTES = 60;

    public function invitationUrl(User $user): string
    {
        return $this->sign($user, self::PURPOSE_INVITATION, self::INVITATION_TTL_MINUTES);
    }

    public function passwordResetUrl(User $user): string
    {
        return $this->sign($user, self::PURPOSE_PASSWORD_RESET, self::RESET_TTL_MINUTES);
    }

    /**
     * Verify a fingerprint presented by the client against the user's current state.
     *
     * Signature and expiry are checked by the `signed` middleware on the route; this method
     * checks the part the framework cannot know about — that the credential state the link
     * was issued against has not changed.
     */
    public function verify(User $user, string $purpose, string $fingerprint): void
    {
        if (! hash_equals($this->fingerprint($user, $purpose), $fingerprint)) {
            throw new AccountLinkInvalid($purpose);
        }
    }

    private function sign(User $user, string $purpose, int $ttlMinutes): string
    {
        // The SPA owns the screen the user lands on, so the signed URL points at the API
        // route and the front end reads the parameters from it. Signing the API route rather
        // than a front-end path keeps verification server-side.
        return URL::temporarySignedRoute(
            name: 'api.v1.account-link.consume',
            expiration: now()->addMinutes($ttlMinutes),
            parameters: [
                'user' => $user->getKey(),
                'purpose' => $purpose,
                'fp' => $this->fingerprint($user, $purpose),
            ],
        );
    }

    /**
     * Binds the link to the user's current credential state.
     *
     * The e-mail address is included so that changing it also invalidates outstanding
     * links — otherwise an invitation sent to a mistyped address would remain redeemable
     * after the address was corrected.
     */
    private function fingerprint(User $user, string $purpose): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $purpose,
                (string) $user->getKey(),
                strtolower($user->email),
                // Null for an invited user who has never set a password, which is exactly
                // the state that must change for the invitation link to die.
                $user->password ?? 'no-credential',
            ]),
            (string) config('app.key'),
        );
    }
}
