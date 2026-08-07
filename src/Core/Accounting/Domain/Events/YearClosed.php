<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Events;

use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fiscal year was closed and its result moved to retained earnings.
 *
 * The entry is nullable: a year with no trading closes without one, because writing a zero entry
 * would be noise that still has to balance against nothing.
 */
final class YearClosed
{
    use Dispatchable;

    public function __construct(
        public readonly FiscalYear $year,
        public readonly ?JournalEntry $closingEntry = null,
        public readonly ?User $actor = null,
    ) {}
}
