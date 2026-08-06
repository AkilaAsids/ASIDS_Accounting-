<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A permission was requested that a workspace role may not hold — either it is a
 * platform staff capability, or it does not exist in the catalogue at all.
 *
 * Both are reported as one failure: distinguishing them would let a caller enumerate
 * the platform's internal capabilities by probing.
 */
final class PermissionNotGrantable extends BusinessRuleViolation
{
    /**
     * @param  list<string>  $names
     */
    public static function these(array $names): self
    {
        return new self(
            'One or more of the selected permissions cannot be granted in this workspace.',
            'permission-not-grantable',
            ['permissions' => array_values($names)],
        );
    }
}
