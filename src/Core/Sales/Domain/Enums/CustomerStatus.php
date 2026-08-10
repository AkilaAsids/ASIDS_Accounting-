<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Enums;

/**
 * Where a customer stands in its lifecycle.
 *
 * Three states rather than a boolean, because "not active" means two different things to the person
 * looking at the list. `Inactive` is a customer you expect to sell to again — dormant, still offered
 * in a search. `Archived` is one you do not, and it is hidden from pickers entirely.
 *
 * Neither state deletes anything. A customer with invoices against it can never be removed: the
 * invoices are statutory records and they name their customer, so the record has to outlive the
 * relationship.
 */
enum CustomerStatus: string
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
     * Whether a new invoice may name this customer.
     *
     * Existing invoices are unaffected by either non-active state — an invoice already issued to a
     * customer who has since gone dormant is still owed and still collectable.
     */
    public function acceptsNewInvoices(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the customer should appear in a picker by default.
     *
     * Inactive customers are findable but not offered: someone reactivating a dormant account needs
     * to see it, while someone typing an invoice does not need it in the way.
     */
    public function isSelectable(): bool
    {
        return $this !== self::Archived;
    }
}
