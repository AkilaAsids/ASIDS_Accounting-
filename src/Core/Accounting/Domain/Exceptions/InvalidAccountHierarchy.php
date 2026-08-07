<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A parent-child relationship in the chart would not make sense.
 *
 * Each case here would produce a chart that still saves and still balances, and then misreports.
 * A cycle makes the roll-up on every statement non-terminating; a parent of a different type puts
 * an expense's balance into an asset subtotal; a cross-company parent is a tenant-isolation failure
 * wearing a reporting bug's clothes.
 */
final class InvalidAccountHierarchy extends BusinessRuleViolation
{
    public static function cycle(string $code): self
    {
        return new self(
            sprintf('Making “%s” a child of that account would create a loop in the chart.', $code),
            'account-hierarchy-cycle',
            ['code' => $code],
        );
    }

    public static function typeMismatch(string $childType, string $parentType): self
    {
        return new self(
            sprintf('A %s account cannot roll up into a %s account.', $childType, $parentType),
            'account-hierarchy-type-mismatch',
            ['child_type' => $childType, 'parent_type' => $parentType],
        );
    }

    public static function foreignCompany(): self
    {
        return new self(
            'The parent account belongs to a different company.',
            'account-hierarchy-foreign-company',
        );
    }

    public static function parentIsPostable(string $code): self
    {
        return new self(
            sprintf('“%s” has journal entries posted directly to it and cannot also be a heading. Move its postings first, or choose another parent.', $code),
            'account-hierarchy-parent-has-postings',
            ['code' => $code],
        );
    }
}
