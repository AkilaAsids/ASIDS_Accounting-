<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Events;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An entry became part of the financial record.
 *
 * Dispatched after the posting transaction commits, never inside it: a listener that observed an
 * entry the transaction went on to roll back would act on a document that does not exist.
 *
 * Tranche 4 hangs balance maintenance off this. Later phases will hang tax reporting and
 * notifications off the same event rather than reaching into the posting service.
 */
final class JournalEntryPosted
{
    use Dispatchable;

    public function __construct(
        public readonly JournalEntry $entry,
        public readonly ?User $actor = null,
    ) {}
}
