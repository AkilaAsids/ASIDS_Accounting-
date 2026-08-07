<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The period an entry is dated into will not accept postings.
 *
 * The message names the state and what to do about it, because "closed" and "locked" have different
 * remedies: a closed period is reopened by a controller, a locked one has had its year closed or its
 * figures filed and needs unlocking first.
 */
final class PeriodNotOpen extends BusinessRuleViolation
{
    public static function forPosting(string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf('%s is %s. %s', $periodLabel, strtolower($status->label()), $status->description()),
            'period-not-open',
            ['period' => $periodLabel, 'status' => $status->value],
        );
    }
}
