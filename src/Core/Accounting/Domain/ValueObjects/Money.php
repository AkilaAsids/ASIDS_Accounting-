<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\ValueObjects;

use Asids\Core\Accounting\Domain\Exceptions\CurrencyMismatch;
use Asids\Core\Accounting\Domain\Exceptions\InvalidMoneyAmount;
use Stringable;

/**
 * An exact monetary amount.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * PHP has no decimal type. `0.1 + 0.2 !== 0.3` in a float, and an accounting system that stores
 * money in floats produces a trial balance that is out by a few cents for reasons nobody can trace
 * — the single most expensive class of bug in this product, because it destroys trust in every
 * number the system reports and cannot be reproduced from the data.
 *
 * So money is an integer here, always: a count of ten-thousandths of a currency unit. LKR 1,234.56
 * is 12_345_600. Arithmetic is integer arithmetic, which is exact.
 *
 * WHY SCALE 4 RATHER THAN 2
 * -------------------------
 * No currency needs more than three decimal places, so scale 4 looks like one more than necessary.
 * It is not, and the reason is intermediate values. A unit price of LKR 3.3333 across seven units, a
 * VAT rate applied to a subtotal, an allocation of a payment across invoices — each produces a value
 * with more precision than the currency has. A system that rounds those to 2 at every step
 * accumulates error; a system that carries scale 4 and rounds *once*, at the point the amount is
 * posted, does not. Scale 4 matches the `numeric(19,4)` storage exactly, so nothing is approximated
 * crossing the database boundary in either direction.
 *
 * Rounding to the currency's own precision (`companies.currency_precision`) happens at two defined
 * points and nowhere else: when an amount is posted to the ledger, and when it is displayed.
 *
 * WHAT THIS DELIBERATELY REFUSES
 * ------------------------------
 * Arithmetic between different currencies throws rather than silently picking one. Until the FX
 * phase there is no exchange rate in the system, so LKR + USD has no defined answer — and inventing
 * one, even a "sensible" one, is how a ledger ends up with amounts nobody can explain.
 *
 * @immutable
 */
final readonly class Money implements Stringable
{
    /**
     * Ten-thousandths per whole currency unit. Fixed, and the same number the database column uses.
     */
    public const int SCALE = 4;

    private const int FACTOR = 10_000;

    /**
     * @param  int  $minorUnits  Ten-thousandths of a currency unit.
     * @param  string  $currency  ISO 4217 alpha-3, uppercase.
     */
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    public function __toString(): string
    {
        return $this->currency.' '.$this->toDecimalString();
    }

    /**
     * From a decimal string — the form the database returns for `numeric(19,4)` and the form an API
     * payload carries.
     *
     * A string, not a float, and that is the whole point: accepting a float here would reintroduce
     * the imprecision this class exists to prevent, one layer further in where it is harder to see.
     */
    public static function of(string $amount, string $currency): self
    {
        $currency = self::normaliseCurrency($currency);
        $trimmed = trim($amount);

        if (! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $trimmed)) {
            // Deliberately strict. More than four decimal places is not a rounding opportunity — it
            // means the caller is working at a precision this type cannot represent, and silently
            // discarding the excess is how a total stops matching the sum of its parts.
            throw InvalidMoneyAmount::malformed($amount);
        }

        $negative = str_starts_with($trimmed, '-');
        $digits = ltrim($trimmed, '-');

        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        $minorUnits = ((int) $whole) * self::FACTOR + (int) str_pad($fraction, self::SCALE, '0');

        return new self($negative ? -$minorUnits : $minorUnits, $currency);
    }

    /**
     * From ten-thousandths directly. Used at the persistence boundary and by `allocate()`.
     */
    public static function ofMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Multiply by a quantity or rate, rounding half away from zero.
     *
     * Half away from zero rather than PHP's default banker's rounding: it is what every accountant
     * and every Sri Lankan tax authority expects, and a system whose totals differ from the ones a
     * bookkeeper reaches with a calculator will be reported as broken regardless of which rule is
     * statistically fairer.
     */
    public function multipliedBy(string $factor): self
    {
        if (! preg_match('/^-?\d{1,15}(\.\d{1,10})?$/', trim($factor))) {
            throw InvalidMoneyAmount::malformedFactor($factor);
        }

        // Scaled integer arithmetic throughout: converting to float here would undo the exactness
        // the rest of the class maintains.
        $factorScale = 10;
        $factorUnits = self::scaledInteger(trim($factor), $factorScale);

        $product = $this->minorUnits * $factorUnits;
        $divisor = 10 ** $factorScale;

        return new self(self::divideRoundingHalfAwayFromZero($product, $divisor), $this->currency);
    }

    /**
     * Split an amount across weights without losing or inventing a cent.
     *
     * THE REASON THIS IS NOT A LOOP OF DIVISIONS
     * ------------------------------------------
     * Dividing 100.00 three ways gives 33.333…, which rounds to 33.33 three times and totals 99.99.
     * The missing cent has to go somewhere, and "somewhere" must be deterministic — an allocation
     * that loses a cent produces an unbalanced journal entry, and one that invents a cent produces a
     * different one.
     *
     * The largest-remainder method distributes the shortfall one minor unit at a time to the
     * weights with the largest fractional remainders, so the parts always sum exactly to the whole.
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw InvalidMoneyAmount::noAllocationWeights();
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw InvalidMoneyAmount::negativeAllocationWeight($weight);
            }
        }

        $total = array_sum($weights);

        if ($total === 0) {
            throw InvalidMoneyAmount::zeroAllocationWeight();
        }

        $shares = [];
        $remainders = [];
        $distributed = 0;

        foreach ($weights as $index => $weight) {
            $exact = $this->minorUnits * $weight;

            // `intdiv` truncates toward zero, so every share is at or below its exact value and the
            // undistributed remainder always has the same sign as the total. That is what makes the
            // top-up loop below terminate correctly for negative amounts as well as positive ones.
            $share = intdiv($exact, $total);

            $shares[$index] = $share;
            $remainders[$index] = abs($exact - $share * $total);
            $distributed += $share;
        }

        $shortfall = $this->minorUnits - $distributed;
        $step = $shortfall < 0 ? -1 : 1;

        // Largest remainder first; ties broken by original order so the result is deterministic
        // rather than dependent on the sort's stability.
        arsort($remainders);

        foreach (array_keys($remainders) as $index) {
            if ($shortfall === 0) {
                break;
            }

            $shares[$index] += $step;
            $shortfall -= $step;
        }

        ksort($shares);

        return array_values(array_map(
            fn (int $share): self => new self($share, $this->currency),
            $shares,
        ));
    }

    /**
     * Round to a currency's own precision, half away from zero.
     *
     * Called when an amount is posted, so the ledger holds values that exist in the currency. A line
     * of LKR 10.0050 is not a number anyone can pay.
     */
    public function roundedTo(int $precision): self
    {
        if ($precision < 0 || $precision > self::SCALE) {
            throw InvalidMoneyAmount::unsupportedPrecision($precision);
        }

        $divisor = 10 ** (self::SCALE - $precision);

        return new self(
            self::divideRoundingHalfAwayFromZero($this->minorUnits, $divisor) * $divisor,
            $this->currency,
        );
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * The decimal string the database column stores. Always four decimal places, so a round trip
     * through `numeric(19,4)` returns exactly what went in.
     *
     * Typed as a plain string rather than `numeric-string`: the format is guaranteed by construction,
     * but PHPStan cannot see that through `sprintf`, and the only ways to make it agree are a cast or
     * an assertion — both of which assert the property rather than establishing it.
     */
    public function toDecimalString(): string
    {
        $negative = $this->minorUnits < 0;
        $units = abs($this->minorUnits);

        $whole = intdiv($units, self::FACTOR);
        $fraction = $units % self::FACTOR;

        return sprintf('%s%d.%04d', $negative ? '-' : '', $whole, $fraction);
    }

    /**
     * Half away from zero, on integers.
     *
     * `intdiv` truncates, and PHP's `round()` returns a float — which would reintroduce imprecision
     * for values beyond 2^53. Doing it by hand keeps the whole class in integer arithmetic.
     */
    private static function divideRoundingHalfAwayFromZero(int $dividend, int $divisor): int
    {
        $negative = ($dividend < 0) !== ($divisor < 0);

        $quotient = intdiv(abs($dividend), abs($divisor));
        $remainder = abs($dividend) % abs($divisor);

        if ($remainder * 2 >= abs($divisor)) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * A decimal string as an integer at the given scale.
     */
    private static function scaledInteger(string $value, int $scale): int
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        $units = ((int) $whole) * (10 ** $scale) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');

        return $negative ? -$units : $units;
    }

    private static function normaliseCurrency(string $currency): string
    {
        $normalised = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $normalised)) {
            throw InvalidMoneyAmount::malformedCurrency($currency);
        }

        return $normalised;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            // No exchange rate exists in the platform until the FX phase, so there is no correct
            // answer to return here. Throwing is the only honest option.
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
