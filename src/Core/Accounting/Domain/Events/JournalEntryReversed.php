<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Events;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A posted entry was undone by a mirror entry.
 *
 * Carries both: the original, now marked reversed, and the reversal that cancels it. Both remain in
 * the ledger — a listener recalculating balances must count both, or it will remove the original's
 * effect twice.
 */
final class JournalEntryReversed
{
    use Dispatchable;

    public function __construct(
        public readonly JournalEntry $original,
        public readonly JournalEntry $reversal,
        public readonly ?User $actor = null,
    ) {}
}
