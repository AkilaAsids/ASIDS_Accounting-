<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Illuminate\Support\Str;

/**
 * Keeps a generated ledger narration inside the column that has to store it.
 *
 * WHY THIS EXISTS
 * ---------------
 * Issuing an invoice writes four descriptions into the ledger: one on the journal entry, and one on each of
 * the receivable, revenue and tax lines. Every one of them is composed from names the user controls —
 * `customers.name` and `accounts.name` are both `varchar(255)` and the customer request permits all 255 — while
 * `journal_entries.description` and `journal_lines.description` are `varchar(255)` too. Composed without a
 * limit, a long but entirely valid trading name pushed a description past its column, Postgres raised `22001`,
 * and the invoice could not be issued at all: a generic 500 for a customer record the system had accepted
 * without complaint. Four sites, one rule.
 *
 * WHY TRUNCATING THE WHOLE STRING IS THE RIGHT RULE
 * ------------------------------------------------
 * Each of the four narrations puts its most identifying part first — the invoice number, or the account name —
 * and the user-supplied name last. Clipping the tail therefore keeps exactly what a reader needs to place the
 * entry, at every site, without this class knowing anything about their shapes. The alternative, giving each
 * site a per-part character budget, needs a different calculation for each and gets a fourth one wrong the
 * first time a fifth narration is added.
 *
 * Two of the sites compose *two* user-controlled names (`account.name — customer.name`), where no per-part
 * budget works anyway: a 250-character account name leaves nothing to award the customer.
 *
 * WHAT THIS IS NOT
 * ----------------
 * Not a rule about display. Nothing is hidden from the user by it: the description is a convenience for a
 * human reading the ledger, and the entry is tied to its invoice by `source_id`, which is exact and never
 * truncated. Amounts, accounts, line ordering and grouping are untouched by anything here.
 */
final readonly class LedgerNarration
{
    /**
     * The width of `journal_entries.description` and `journal_lines.description`.
     *
     * Restated rather than read from the schema: a service cannot ask a column how wide it is without a query
     * on every posting. Declared as this module's own knowledge of a neighbouring constraint, and asserted by
     * test — so if the Accounting column ever narrows, a test fails rather than a customer's invoice.
     */
    public const int LIMIT = 255;

    /**
     * The narration, clipped to the column with a visible marker when it did not fit.
     *
     * Measured in characters, not bytes, because that is what `varchar` counts — the em dash these narrations
     * use as a separator is one character and three bytes, and `strlen` would reserve three times the space it
     * needs and clip narrations that fitted perfectly well.
     *
     * `Str::limit` appends the marker *after* its limit, so the budget passed is one short of the column width;
     * that is what lands the result on 255 rather than one character over it.
     */
    public static function limit(string $narration): string
    {
        if (mb_strlen($narration) <= self::LIMIT) {
            return $narration;
        }

        return Str::limit($narration, self::LIMIT - 1, '…');
    }
}
