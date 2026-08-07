<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

final class InvalidDateRange extends BusinessRuleViolation
{
    public static function endBeforeStart(string $start, string $end): self
    {
        return new self(
            sprintf('A date range cannot end (%s) before it starts (%s).', $end, $start),
            'invalid-date-range',
            ['start' => $start, 'end' => $end],
        );
    }
}
