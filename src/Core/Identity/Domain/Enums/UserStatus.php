<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Enums;

/**
 * A user account's lifecycle.
 *
 * Deactivated is distinct from deleted, and that distinction is not cosmetic: an
 * accounting system must keep the identity that approved a journal entry three
 * years ago resolvable, so accounts are retired rather than removed.
 */
enum UserStatus: string
{
    /** Invited; has not yet set a password. */
    case PendingInvitation = 'pending_invitation';

    case Active = 'active';

    /** Temporarily barred — a security investigation, a leave of absence. */
    case Suspended = 'suspended';

    /** Left the organisation. Retained for audit attribution. */
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::PendingInvitation => 'Invitation pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Deactivated => 'Deactivated',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the account still counts against the tenant's licensed seat count.
     */
    public function consumesSeat(): bool
    {
        return $this === self::Active || $this === self::PendingInvitation;
    }

    public function signInDeniedReason(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::PendingInvitation => 'Your invitation has not been accepted yet. Check your e-mail for the invitation link.',
            self::Suspended => 'This account has been suspended. Contact your administrator.',
            self::Deactivated => 'This account is no longer active.',
        };
    }
}
