<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Domain\Enums;

/**
 * Where a setting override lives.
 *
 * Ordered most specific to least. Resolution walks outwards and stops at the first override it
 * finds, falling back to the definition's default in code. Only overrides are stored, which is
 * what keeps this table small across a hundred thousand tenants instead of holding a hundred
 * thousand copies of every default.
 */
enum SettingScope: string
{
    /** Personal preference: date format, notification opt-outs. */
    case User = 'user';

    /** Per legal entity: invoice numbering, document footers. */
    case Company = 'company';

    /** Workspace-wide: branding, security policy. */
    case Tenant = 'tenant';

    /** Platform-wide, set by ASIDS. Never editable by a customer. */
    case System = 'system';

    /**
     * Resolution order, most specific first.
     *
     * @return list<self>
     */
    public static function resolutionOrder(): array
    {
        return [self::User, self::Company, self::Tenant, self::System];
    }

    public function requiresTarget(): bool
    {
        return $this === self::User || $this === self::Company;
    }

    public function requiresTenant(): bool
    {
        return $this !== self::System;
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'Personal',
            self::Company => 'Company',
            self::Tenant => 'Workspace',
            self::System => 'Platform',
        };
    }
}
