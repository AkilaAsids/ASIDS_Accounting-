<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Infrastructure\Ledger;

use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Domain\Models\Account;

/**
 * The truthful answer until the ledger exists.
 *
 * Not a stub and not a placeholder: `journal_lines` is created in tranche 3, so before then no
 * account can possibly have a posting, and `false` is the correct answer rather than a convenient
 * one. It is replaced by `EloquentAccountUsageProbe` in the same tranche that creates the table —
 * the binding moves, the rules do not.
 */
final class NoPostings implements AccountUsageProbe
{
    public function hasPostings(Account $account): bool
    {
        return false;
    }

    public function subtreeHasPostings(Account $account): bool
    {
        return false;
    }
}
