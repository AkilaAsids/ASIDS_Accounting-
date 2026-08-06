<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A role still assigned to users cannot be deleted.
 *
 * Cascading the delete would silently strip those users of access — a change nobody
 * asked for, discovered when they cannot sign in. The count is disclosed so the
 * administrator knows the size of the job.
 */
final class RoleInUse extends BusinessRuleViolation
{
    public static function by(string $label, int $userCount): self
    {
        return new self(
            sprintf(
                '“%s” is assigned to %d user(s). Reassign them before deleting the role.',
                $label,
                $userCount,
            ),
            'role-in-use',
            ['role' => $label, 'assigned_users' => $userCount],
        );
    }
}
