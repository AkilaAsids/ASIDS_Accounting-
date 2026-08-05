<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Enums;

/**
 * Outcome of an authentication attempt, as recorded in `login_histories`.
 *
 * The set is finer-grained than "success or failure" because the security value is
 * in the distinctions: a burst of TwoFactorFailed against one account is a stolen
 * password, whereas a burst of Failed across many accounts is credential stuffing,
 * and the two warrant different responses.
 */
enum LoginOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case LockedOut = 'locked_out';
    case TwoFactorRequired = 'two_factor_required';
    case TwoFactorFailed = 'two_factor_failed';
    case PasswordExpired = 'password_expired';
    case AccountInactive = 'account_inactive';

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether this outcome should increment the account's failed-attempt counter.
     *
     * TwoFactorRequired must not: the password was correct, and counting it would
     * lock out every user who simply takes a moment to open their authenticator.
     */
    public function countsTowardsLockout(): bool
    {
        return $this === self::Failed || $this === self::TwoFactorFailed;
    }
}
