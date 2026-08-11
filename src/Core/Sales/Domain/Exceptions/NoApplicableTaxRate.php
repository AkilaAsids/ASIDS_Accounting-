<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * No tax rate covers the date being asked about.
 *
 * Raised rather than returning zero, and that is the entire point of this class. A resolver that
 * answered 0% when it found nothing would produce an invoice with no tax on it, which looks correct on
 * screen, posts a balanced entry, ties in the trial balance, and understates a VAT return. Nothing
 * downstream could detect it. The only safe failure is a loud one.
 *
 * Three distinct situations reach here, and the messages separate them because the remedies differ: the
 * code does not exist at all, it exists but every range is inactive, or it exists and has a gap where
 * the requested date falls. The third is the one a user creates by ending a range and forgetting to open
 * the next.
 */
final class NoApplicableTaxRate extends BusinessRuleViolation
{
    public static function forCode(string $code, CarbonImmutable $date): self
    {
        return new self(
            sprintf(
                'No tax code %s is configured for %s. Add one before invoicing against it.',
                $code,
                $date->toDateString(),
            ),
            'no-applicable-tax-rate',
            ['code' => $code, 'date' => $date->toDateString()],
        );
    }

    /**
     * The code has ranges, but none of them covers this date.
     *
     * Named separately because the fix is different: something exists to correct rather than to create,
     * and the user needs to know the code is not the problem — the dates are.
     */
    public static function outsideEveryRange(string $code, CarbonImmutable $date): self
    {
        return new self(
            sprintf(
                'Tax code %s has no rate covering %s. Its ranges leave that date uncovered — check for a gap '
                .'between one range ending and the next beginning.',
                $code,
                $date->toDateString(),
            ),
            'tax-rate-date-not-covered',
            ['code' => $code, 'date' => $date->toDateString()],
        );
    }

    public static function becauseInactive(string $code, CarbonImmutable $date): self
    {
        return new self(
            sprintf(
                'Tax code %s covers %s but is inactive, so it cannot be applied. Reactivate it, or use a '
                .'different code.',
                $code,
                $date->toDateString(),
            ),
            'tax-rate-inactive',
            ['code' => $code, 'date' => $date->toDateString()],
        );
    }
}
