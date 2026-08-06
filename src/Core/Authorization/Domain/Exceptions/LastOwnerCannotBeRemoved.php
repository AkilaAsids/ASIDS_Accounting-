<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The workspace must always have at least one owner.
 *
 * Removing the last one would leave nobody able to manage billing, invite users or
 * transfer ownership — a state only ASIDS support could repair, so it is refused at
 * the source.
 */
final class LastOwnerCannotBeRemoved extends BusinessRuleViolation
{
    /**
     * Named specifically rather than `make()`: the parent declares a static
     * `make(string $code, string $message, array $context)`, and a no-argument override of it is
     * an incompatible signature — a fatal error the moment the class is autoloaded.
     */
    public static function forWorkspace(): self
    {
        return new self(
            'This is the only owner of the workspace. Make another user an owner before removing this one.',
            'last-owner-protected',
        );
    }
}
