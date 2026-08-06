<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * Privilege escalation refused.
 *
 * A user may only grant roles strictly below their own level. Without this an
 * Administrator could mint a second Owner — or promote themselves — which would make
 * the level column decorative.
 */
final class RoleNotGrantable extends BusinessRuleViolation
{
    public static function aboveOwnLevel(string $label): self
    {
        return new self(
            sprintf('You cannot assign “%s”, because it grants at least as much authority as your own role.', $label),
            'role-not-grantable',
            ['role' => $label],
        );
    }

    public static function ownerRole(): self
    {
        return new self(
            'The owner role is transferred through the ownership handover process, not assigned directly.',
            'owner-role-not-assignable',
        );
    }

    public static function platformTemplate(): self
    {
        return new self(
            'That is a platform role template. Create a role in your workspace from it instead of assigning it directly.',
            'template-role-not-assignable',
        );
    }
}
