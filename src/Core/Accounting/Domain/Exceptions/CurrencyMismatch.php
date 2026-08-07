<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * Two amounts in different currencies were combined.
 *
 * There is no exchange rate anywhere in the platform until the FX phase, so this operation has no
 * defined answer. Returning one anyway — by assuming parity, or by silently adopting the left-hand
 * currency — would put a number into a financial record that nobody can later explain or reproduce.
 *
 * When the FX phase lands, the fix is not to relax this rule. It is to convert explicitly, at a
 * stated rate, on a stated date, before the amounts meet.
 */
final class CurrencyMismatch extends BusinessRuleViolation
{
    public static function between(string $left, string $right): self
    {
        return new self(
            sprintf('Amounts in %s and %s cannot be combined. Convert one of them first.', $left, $right),
            'currency-mismatch',
            ['left' => $left, 'right' => $right],
        );
    }
}
