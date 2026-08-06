<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * An attempt to rename or delete one of the roles every workspace is provisioned with.
 *
 * Their permissions remain fully editable — only their identity is fixed, because
 * provisioning, the seeders and any customer integration all refer to `owner` and
 * `administrator` by name.
 */
final class SystemRoleIsProtected extends BusinessRuleViolation
{
    public static function cannotRename(string $label): self
    {
        return new self(
            sprintf('“%s” is a built-in role and cannot be renamed. You can still change which permissions it grants.', $label),
            'system-role-protected',
            ['role' => $label],
        );
    }

    public static function cannotDelete(string $label): self
    {
        return new self(
            sprintf('“%s” is a built-in role and cannot be deleted. Remove it from users instead, or change its permissions.', $label),
            'system-role-protected',
            ['role' => $label],
        );
    }
}
