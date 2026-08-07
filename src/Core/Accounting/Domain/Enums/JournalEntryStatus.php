<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * Where a journal entry is in its life.
 *
 * Only one transition is reversible, and the asymmetry is the point:
 *
 *   draft → posted     the entry becomes part of the record
 *   posted → reversed  a *new* entry undoes it; this one is untouched apart from the flag
 *
 * A posted entry is never edited and never deleted. That is not caution, it is what makes the audit
 * trail worth having: an auditor reading the books must see the mistake and the correction, not a
 * tidy history in which the mistake never happened.
 */
enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    /**
     * Whether the entry's lines still affect any balance.
     *
     * A reversed entry does: both it and its reversal remain in the ledger, and they cancel. Removing
     * either from the balance calculation would leave the trial balance out by the entry's amount.
     */
    public function affectsBalances(): bool
    {
        return $this !== self::Draft;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted || $this === self::Reversed;
    }
}
