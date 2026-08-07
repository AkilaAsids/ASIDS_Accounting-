<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * Debits do not equal credits.
 *
 * The database refuses this too, at commit, via a deferred constraint trigger. This exists so the
 * customer is told *by how much* and on which side, rather than receiving a constraint name — and so
 * the failure arrives before a transaction has been opened and rolled back.
 *
 * Both checks are deliberate. This one is for people; the trigger is for every path that does not
 * come through here.
 */
final class UnbalancedEntry extends BusinessRuleViolation
{
    public static function by(Money $debits, Money $credits): self
    {
        $difference = $debits->minus($credits);

        return new self(
            sprintf(
                'This entry does not balance. Debits total %s and credits total %s — a difference of %s.',
                $debits->toDecimalString(),
                $credits->toDecimalString(),
                $difference->absolute()->toDecimalString(),
            ),
            'unbalanced-entry',
            [
                'debits' => $debits->toDecimalString(),
                'credits' => $credits->toDecimalString(),
                'difference' => $difference->toDecimalString(),
            ],
        );
    }

    public static function noLines(): self
    {
        return new self(
            'An entry needs at least two lines: something debited and something credited.',
            'entry-has-no-lines',
        );
    }

    public static function singleLine(): self
    {
        return new self(
            'An entry needs at least two lines. A single line cannot balance against anything.',
            'entry-has-one-line',
        );
    }
}
