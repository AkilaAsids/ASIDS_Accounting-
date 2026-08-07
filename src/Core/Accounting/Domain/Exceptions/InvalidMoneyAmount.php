<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A monetary value could not be interpreted exactly.
 *
 * Every case here is a refusal to guess. An amount with five decimal places, a malformed currency
 * code, an allocation across zero weights — each has a plausible "helpful" interpretation, and each
 * of those interpretations silently changes a number in a financial record.
 */
final class InvalidMoneyAmount extends BusinessRuleViolation
{
    public static function malformed(string $amount): self
    {
        return new self(
            sprintf('“%s” is not an amount this system can represent exactly. Use up to 15 digits and at most 4 decimal places.', $amount),
            'invalid-money-amount',
            ['amount' => $amount],
        );
    }

    public static function malformedFactor(string $factor): self
    {
        return new self(
            sprintf('“%s” is not a valid multiplier.', $factor),
            'invalid-money-factor',
            ['factor' => $factor],
        );
    }

    public static function malformedCurrency(string $currency): self
    {
        return new self(
            sprintf('“%s” is not an ISO 4217 currency code.', $currency),
            'invalid-currency-code',
            ['currency' => $currency],
        );
    }

    public static function unsupportedPrecision(int $precision): self
    {
        return new self(
            sprintf('A currency precision of %d is outside the 0 to 4 this system stores.', $precision),
            'unsupported-currency-precision',
            ['precision' => $precision],
        );
    }

    public static function noAllocationWeights(): self
    {
        return new self(
            'An amount cannot be allocated across an empty set of weights.',
            'invalid-allocation',
        );
    }

    public static function zeroAllocationWeight(): self
    {
        return new self(
            'An amount cannot be allocated when every weight is zero.',
            'invalid-allocation',
        );
    }

    public static function negativeAllocationWeight(int $weight): self
    {
        return new self(
            sprintf('An allocation weight cannot be negative; received %d.', $weight),
            'invalid-allocation',
            ['weight' => $weight],
        );
    }
}
