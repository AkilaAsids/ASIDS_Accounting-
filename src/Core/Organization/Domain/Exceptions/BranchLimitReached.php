<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A guard rail rather than a commercial limit: branches are not licensed per unit, but an
 * unbounded count would let one workspace degrade every branch picker and report grouping
 * on the platform.
 */
final class BranchLimitReached extends BusinessRuleViolation
{
    public static function at(int $limit): self
    {
        return new self(
            sprintf('A company may have at most %d branches. Archive a branch you no longer operate.', $limit),
            'branch-limit-reached',
            ['branch_limit' => $limit],
        );
    }
}
