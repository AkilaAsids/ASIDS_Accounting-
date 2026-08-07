<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * How open a fiscal period is to new postings.
 *
 * Three states rather than two, because "closed" and "locked" answer different questions and
 * conflating them costs a customer either safety or the ability to fix a mistake.
 *
 *   * **Open** — normal operation.
 *   * **Closed** — the month has been reconciled and reported. Nothing posts here, but a controller
 *     may reopen it, and doing so is audited. This is the state a month spends most of its life in.
 *   * **Locked** — the year has been closed, or a statutory filing has been made against this
 *     period. Reopening requires unlocking first, which is a separate, higher-privileged act.
 *
 * A two-state model forces a choice between an accountant who cannot correct a genuine error and an
 * accountant who can silently alter a period already reported to the tax authority.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }

    /**
     * Whether an entry may be posted into a period in this state.
     */
    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether this state can be returned to `Open` by the reopen operation alone.
     *
     * A locked period cannot: it must be unlocked first, deliberately, by someone holding the
     * capability to do so. That extra step is the whole reason the state exists.
     */
    public function isReopenable(): bool
    {
        return $this === self::Closed;
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Entries can be posted into this period.',
            self::Closed => 'The period has been closed. Reopen it to post further entries.',
            self::Locked => 'The period is locked because its year has been closed or it has been reported. It must be unlocked before it can be reopened.',
        };
    }
}
