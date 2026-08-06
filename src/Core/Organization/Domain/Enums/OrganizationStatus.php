<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Enums;

/**
 * Lifecycle shared by companies and branches.
 *
 * There is no "deleted" case, and that is the point: an entity that has appeared on a
 * financial statement can never be removed, only closed to further activity. Archiving
 * preserves every historical transaction while keeping the entity out of pickers and
 * defaults.
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether new transactions may be recorded against this entity.
     */
    public function permitsPosting(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the entity may be selected in a picker or used as a default.
     */
    public function isSelectable(): bool
    {
        return $this === self::Active;
    }
}
