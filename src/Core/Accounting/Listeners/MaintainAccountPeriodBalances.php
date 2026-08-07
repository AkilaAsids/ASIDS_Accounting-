<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Listeners;

use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Domain\Events\JournalEntryPosted;
use Asids\Core\Accounting\Domain\Events\JournalEntryReversed;

/**
 * Keeps `account_period_balances` in step with the ledger.
 *
 * Synchronous, deliberately. Queueing this would leave a window in which the ledger and every report
 * drawn from it disagree, with nothing to say which is right — and the window would be longest
 * exactly when the queue is backed up, which is when someone is most likely to be looking.
 *
 * A reversal touches two entries: the original, whose accounts are unchanged in total but whose
 * period may differ from the reversal's, and the reversal itself. Both are applied, because a
 * reversal dated in a later month moves nothing in the original's period and everything in its own.
 */
final readonly class MaintainAccountPeriodBalances
{
    public function __construct(private LedgerBalanceService $balances) {}

    public function handlePosted(JournalEntryPosted $event): void
    {
        $this->balances->applyEntry($event->entry);
    }

    public function handleReversed(JournalEntryReversed $event): void
    {
        // Both, and in this order only for readability — each recomputes from the lines, so neither
        // depends on the other having run.
        $this->balances->applyEntry($event->original);
        $this->balances->applyEntry($event->reversal);
    }
}
