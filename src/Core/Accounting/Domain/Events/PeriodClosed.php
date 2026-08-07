<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Events;

use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A period stopped accepting postings.
 *
 * Later phases listen for this: tax reporting to snapshot a return's figures, notifications to tell a
 * controller the month is shut. Nothing in this phase does — the event exists so those do not have to
 * reach into the close service.
 */
final class PeriodClosed
{
    use Dispatchable;

    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly ?User $actor = null,
    ) {}
}
