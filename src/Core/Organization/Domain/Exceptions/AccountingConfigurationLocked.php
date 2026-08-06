<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * An attempt to change a company's base currency or fiscal calendar after its books have
 * activity.
 *
 * Neither can be changed in place: the stored amounts carry no currency of their own, so
 * reinterpreting them would silently restate every historical balance, and moving the
 * fiscal boundary would relocate posted transactions into a different period — and
 * potentially into a closed one.
 */
final class AccountingConfigurationLocked extends BusinessRuleViolation
{
    public static function baseCurrency(string $current): self
    {
        return new self(
            sprintf('The base currency cannot be changed once transactions have been recorded. This company reports in %s.', $current),
            'base-currency-locked',
            ['base_currency_code' => $current],
        );
    }

    public static function fiscalCalendar(?string $earliestActivity = null): self
    {
        return new self(
            'The fiscal year start cannot be changed once transactions have been recorded, because it would move existing entries into a different period.',
            'fiscal-calendar-locked',
            array_filter(['earliest_activity' => $earliestActivity]),
        );
    }
}
