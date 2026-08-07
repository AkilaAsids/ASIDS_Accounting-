<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Policies;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Who may close and reopen periods.
 *
 * Closing and reopening are deliberately different permissions. Closing is routine month-end work an
 * accountant does; reopening changes figures that may already have been filed with a bank or a tax
 * authority, and whoever signed those off should be the one deciding to move them.
 */
final class FiscalPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.periods.view');
    }

    public function view(User $user, FiscalPeriod $period): bool
    {
        return $user->can('accounting.periods.view')
            && $user->canAccessCompany($period->company_id);
    }

    public function close(User $user, FiscalPeriod $period): bool
    {
        return $period->status === PeriodStatus::Open
            && $user->can('accounting.periods.close')
            && $user->canAccessCompany($period->company_id);
    }

    /**
     * A locked period is not reopenable by anyone through this route.
     *
     * Locked means its year has been closed. The way back is reversing the year-end entry, which is a
     * different and more deliberate act than reopening a month.
     */
    public function reopen(User $user, FiscalPeriod $period): bool
    {
        return $period->status === PeriodStatus::Closed
            && $user->can('accounting.periods.reopen')
            && $user->canAccessCompany($period->company_id);
    }
}
