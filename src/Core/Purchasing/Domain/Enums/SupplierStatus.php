<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Enums;

/**
 * Where a supplier stands in its lifecycle.
 *
 * Three states rather than a boolean, because "not active" means two different things to the person
 * looking at the list. `Inactive` is a supplier you expect to buy from again — dormant, still offered
 * in a search. `Archived` is one you do not, and it is hidden from pickers entirely.
 *
 * Neither state deletes anything. A supplier with bills against it can never be removed: the bills are
 * statutory records and they name their supplier, so the record has to outlive the relationship.
 */
enum SupplierStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether a new bill may name this supplier.
     *
     * Existing bills are unaffected by either non-active state — a bill already recorded against a
     * supplier who has since gone dormant is still owed and still payable.
     */
    public function acceptsNewBills(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the supplier should appear in a picker by default.
     *
     * Inactive suppliers are findable but not offered: someone reactivating a dormant account needs
     * to see it, while someone recording a bill does not need it in the way.
     */
    public function isSelectable(): bool
    {
        return $this !== self::Archived;
    }
}
