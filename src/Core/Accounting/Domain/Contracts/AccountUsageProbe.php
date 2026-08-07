<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Contracts;

use Asids\Core\Accounting\Domain\Models\Account;

/**
 * Answers "has anything been posted to this account?".
 *
 * The chart of accounts needs this answer to enforce its two most important rules — an account with
 * postings cannot be reclassified or deleted — but the chart is built before the ledger it would
 * query, and the rules have to exist from the moment accounts do.
 *
 * The same seam Phase 1 used for `LedgerActivityProbe`, and for the same reason: a module states
 * what it needs to know without depending on a table that does not exist yet. `NoPostings` reports
 * the truth until tranche 3 creates `journal_lines`, at which point the real implementation is bound
 * and the rules begin to bite for real.
 */
interface AccountUsageProbe
{
    /**
     * Whether any journal line references this account, in any state.
     *
     * Any state, deliberately — including drafts. An account referenced by a draft entry is about to
     * have history, and reclassifying it in the window before the draft is posted produces exactly
     * the silent misstatement the rule exists to prevent.
     */
    public function hasPostings(Account $account): bool;

    /**
     * Whether any journal line references this account or anything rolling up into it.
     *
     * Asked before a heading account is deleted or reparented: removing a parent whose descendants
     * carry history is the same mistake one level up.
     */
    public function subtreeHasPostings(Account $account): bool;
}
