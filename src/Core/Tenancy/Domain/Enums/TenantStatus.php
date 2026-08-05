<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Enums;

/**
 * Lifecycle of a paying customer's workspace.
 *
 * The distinction between Suspended and Cancelled is commercially important and
 * therefore modelled explicitly: a suspended tenant's data is intact and the
 * workspace returns the moment the invoice is settled, whereas a cancelled tenant
 * is in its retention window before deletion. Collapsing the two would either
 * destroy data on a late payment or keep churned customers on the platform
 * indefinitely.
 */
enum TenantStatus: string
{
    /** Created, provisioning still running. Not yet usable. */
    case Provisioning = 'provisioning';

    /** Fully operational. */
    case Active = 'active';

    /** Access withheld (non-payment, abuse investigation). Data retained. */
    case Suspended = 'suspended';

    /** Subscription ended. In the retention window before deletion. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Provisioning',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * May users of this tenant sign in?
     */
    public function permitsAccess(): bool
    {
        return $this === self::Active;
    }

    /**
     * May the tenant's data still be read by an administrator — for an export
     * during the retention window, or an audit of a suspended account?
     */
    public function permitsAdministrativeRead(): bool
    {
        return $this !== self::Provisioning;
    }

    /**
     * Human-facing explanation shown on the "workspace unavailable" screen.
     */
    public function accessDeniedReason(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Provisioning => 'This workspace is still being prepared. Please try again in a moment.',
            self::Suspended => 'This workspace has been suspended. Please contact your administrator or ASIDS support.',
            self::Cancelled => 'This workspace has been closed. Contact ASIDS support if you need to restore it.',
        };
    }
}
