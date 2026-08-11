<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Domain\Exceptions\InvalidInvoiceDiscount;

/**
 * The arithmetic of an invoice, separated from everything else about one.
 *
 * Its own class because it is the part most worth testing in isolation and the part where a mistake is least
 * visible. A wrong total on screen gets noticed; a total that is right to the rupee and wrong by a hundredth
 * balances, posts, ties in the trial balance and misstates a return.
 *
 * Every figure goes through `Money`, which works in scaled integers. No float touches an amount at any point,
 * and nothing here reimplements arithmetic `Money` already does.
 *
 * THE ORDER OF OPERATIONS IS THE DESIGN
 * -------------------------------------
 *   1. line gross      = quantity × unit price
 *   2. line discount   = a percentage of the gross, or a fixed amount
 *   3. line net        = gross − line discount
 *   4. header discount = allocated across the line nets in proportion to them
 *   5. line subtotal   = line net − its share of the header discount
 *   6. line tax        = subtotal × rate, rounded to the currency's precision
 *   7. line total      = subtotal + tax
 *
 * Tax comes after both discounts because tax is charged on what the customer actually pays. Computing it
 * before would overstate the liability, and the error would be invisible on the invoice — every figure
 * internally consistent, the tax simply wrong.
 *
 * Rounding happens once per line, at step 6, to the company's currency precision. The invoice's `tax_total` is
 * then the *sum of rounded line amounts* rather than a rounding of the sum. That is what makes a printed
 * invoice add up: a reader adding the tax column must reach the total shown.
 */
final readonly class InvoiceTotalsCalculator
{
    /**
     * The header discount, distributed across lines in proportion to their net amounts.
     *
     * `Money::allocate()` does the distribution with the largest-remainder method, so the shares sum exactly to
     * the discount — no cent lost, none invented. It is the only allocation mechanism in the codebase and must
     * stay so.
     *
     * The integer weights it needs come from `Money::$minorUnits`, a public promoted property on a
     * `final readonly` class — so reading it is safe and no accessor was required. ADR 0007's decision B1
     * assumed otherwise and was withdrawn on that basis; `Money` is unchanged.
     *
     * @param  list<Money>  $lineNets
     * @return list<Money> one share per line, in the same order
     */
    public function allocateHeaderDiscount(Money $discount, array $lineNets): array
    {
        if ($lineNets === []) {
            throw BusinessRuleViolation::make(
                'discount-without-lines',
                'A header discount cannot be applied to an invoice with no lines.',
            );
        }

        $weights = [];

        foreach ($lineNets as $net) {
            // A negative or zero line cannot carry a share. `allocate()` refuses negative weights, and rightly:
            // there is no defensible way to spread a discount across a line that reduces the invoice. Refused
            // rather than worked around, because any workaround would be inventing semantics nobody asked for.
            if ($net->isNegative() || $net->isZero()) {
                throw InvalidInvoiceDiscount::headerDiscountWithNonPositiveLine();
            }

            $weights[] = $net->minorUnits;
        }

        return $discount->allocate($weights);
    }

    /**
     * The tax on a line's net amount, at the rate snapshotted onto it.
     *
     * Rounded to the currency's precision here rather than left at the ledger's scale, because this figure is
     * printed on a document and settled in whole cents. `Money::roundedTo()` does it, preserving the
     * half-away-from-zero behaviour the rest of the ledger uses.
     *
     * @param  numeric-string  $ratePercent
     */
    public function taxOnLine(Money $net, string $ratePercent, int $precision): Money
    {
        if (bccomp($ratePercent, '0', Money::SCALE) === 0) {
            return Money::zero($net->currency);
        }

        /** @var numeric-string $factor */
        $factor = bcdiv($ratePercent, '100', 10);

        return $net->multipliedBy($factor)->roundedTo($precision);
    }

    /**
     * A line's gross amount before any discount.
     *
     * `multipliedBy` takes the quantity as a factor, which is exact for anything the column can hold — the
     * method accepts fifteen integer and ten fractional digits, and `quantity` is `numeric(19,4)`.
     *
     * @param  numeric-string  $quantity
     */
    public function lineGross(Money $unitPrice, string $quantity): Money
    {
        return $unitPrice->multipliedBy($quantity);
    }

    /**
     * A line's own discount, from whichever form was given.
     *
     * Both forms set is refused by the DTO's caller and by the database; this asserts it too rather than
     * silently preferring one, because a percentage negotiated and an amount approved are different claims.
     *
     * @param  numeric-string|null  $percent
     * @param  numeric-string|null  $amount
     */
    public function lineDiscount(Money $gross, ?string $percent, ?string $amount): Money
    {
        if ($percent !== null && $amount !== null) {
            throw InvalidInvoiceDiscount::bothFormsGiven();
        }

        if ($percent !== null) {
            if (bccomp($percent, '0', Money::SCALE) < 0 || bccomp($percent, '100', Money::SCALE) > 0) {
                throw InvalidInvoiceDiscount::percentOutOfRange($percent);
            }

            /** @var numeric-string $factor */
            $factor = bcdiv($percent, '100', 10);

            return $gross->multipliedBy($factor);
        }

        if ($amount === null) {
            return Money::zero($gross->currency);
        }

        $discount = Money::of($amount, $gross->currency);

        if ($discount->isNegative()) {
            throw InvalidInvoiceDiscount::negativeAmount();
        }

        // A discount larger than the line is almost certainly a typo, and allowing it would turn a sale into a
        // credit without anyone deciding to.
        if ($discount->isGreaterThan($gross->absolute())) {
            throw InvalidInvoiceDiscount::exceedsLine();
        }

        return $discount;
    }
}
