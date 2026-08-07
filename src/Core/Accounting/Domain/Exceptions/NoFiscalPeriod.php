<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * No fiscal period covers the date an entry is dated.
 *
 * Almost always means the fiscal year has not been opened yet, which is a thing the customer can
 * fix — so the message says that rather than reporting a generic failure. The alternative reading,
 * a gap between two periods, is impossible by construction: periods are generated a whole year at a
 * time.
 */
final class NoFiscalPeriod extends BusinessRuleViolation
{
    public static function forDate(string $date, string $companyName): self
    {
        return new self(
            sprintf('No fiscal period of “%s” covers %s. Open the fiscal year for that date first.', $companyName, $date),
            'no-fiscal-period',
            ['date' => $date, 'company' => $companyName],
        );
    }
}
