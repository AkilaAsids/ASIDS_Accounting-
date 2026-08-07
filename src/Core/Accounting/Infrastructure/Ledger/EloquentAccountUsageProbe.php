<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Infrastructure\Ledger;

use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Illuminate\Support\Facades\DB;

/**
 * The real answer, now that `journal_lines` exists.
 *
 * Replaces `NoPostings`, which was truthful until tranche 3 created the table. Nothing about the
 * rules in `ChartOfAccountsService` changes — reclassification and deletion were already refused for
 * accounts with postings; from here the probe starts finding some.
 *
 * Drafts count. An account referenced by a draft is about to have history, and reclassifying it in
 * the window before the draft is posted produces the same silent misstatement the rule exists to
 * prevent.
 */
final class EloquentAccountUsageProbe implements AccountUsageProbe
{
    public function hasPostings(Account $account): bool
    {
        return JournalLine::query()->forAccount($account->getKey())->exists();
    }

    /**
     * Whether the account or anything rolling up into it carries a line.
     *
     * A recursive CTE rather than a walk in PHP: this is asked before a delete, where the answer must
     * be right for a chart of any depth, and the alternative is loading every account in the company
     * to compute a subtree that is usually two rows.
     */
    public function subtreeHasPostings(Account $account): bool
    {
        /** @var object{exists: bool}|null $result */
        $result = DB::selectOne(<<<'SQL'
            WITH RECURSIVE subtree AS (
                SELECT id FROM accounts WHERE id = ?
                UNION ALL
                SELECT a.id FROM accounts a JOIN subtree s ON a.parent_id = s.id
            )
            SELECT EXISTS (
                SELECT 1 FROM journal_lines l JOIN subtree s ON l.account_id = s.id
            ) AS exists
        SQL, [$account->getKey()]);

        return (bool) ($result->exists ?? false);
    }
}
