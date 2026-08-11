<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * A discount that cannot be applied as asked.
 *
 * Separate messages per cause, because the remedies differ and a single "invalid discount" would leave the
 * user guessing which of five things they got wrong.
 */
final class InvalidInvoiceDiscount extends BusinessRuleViolation
{
    public static function bothFormsGiven(): self
    {
        return new self(
            'A line may carry a discount percentage or a fixed discount amount, not both. A percentage is what '
            .'is negotiated and an amount is what is approved, so there is no correct way to reconcile them.',
            'invoice-line-two-discounts',
        );
    }

    public static function percentOutOfRange(string $percent): self
    {
        return new self(
            sprintf('A discount of %s%% is out of range. A percentage must be between 0 and 100.', $percent),
            'invoice-line-discount-percent-out-of-range',
        );
    }

    public static function negativeAmount(): self
    {
        return new self(
            'A discount cannot be negative. A negative discount is a surcharge, which belongs on a line of its '
            .'own where the customer can see it.',
            'invoice-discount-negative',
        );
    }

    public static function exceedsLine(): self
    {
        return new self(
            'A discount cannot exceed the line it applies to. Allowing it would turn a sale into a credit '
            .'without anyone deciding to.',
            'invoice-line-discount-exceeds-line',
        );
    }

    public static function exceedsInvoice(): self
    {
        return new self(
            'The header discount is larger than the invoice it applies to, which would make the total negative. '
            .'A negative invoice is a credit note, not an invoice with a minus sign.',
            'invoice-discount-exceeds-invoice',
        );
    }

    /**
     * A header discount cannot be spread across a line that reduces the invoice.
     *
     * `Money::allocate()` refuses negative weights, and rightly — there is no defensible share for a credit
     * line to take. Refused rather than worked around, because every workaround invents semantics nobody asked
     * for and the invoice would then be arithmetically defensible and commercially wrong.
     */
    public static function headerDiscountWithNonPositiveLine(): self
    {
        return new self(
            'A header discount cannot be spread across an invoice containing a zero or negative line. Apply the '
            .'discount to the individual lines instead.',
            'invoice-discount-with-non-positive-line',
        );
    }
}
