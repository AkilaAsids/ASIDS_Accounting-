<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Events;

use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed period was reopened.
 *
 * Carries the reason, because that is the point: figures that may already have been reported are
 * about to be able to change, and the trail has to say why someone decided that was correct.
 */
final class PeriodReopened
{
    use Dispatchable;

    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly string $reason,
        public readonly ?User $actor = null,
    ) {}
}
