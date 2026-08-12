<?php

declare(strict_types=1);

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives companies provisioned from chart template `2026.02-lk-sme-1` the Trade Receivables system key.
 *
 * Phase 3 Milestone 5 made Trade Receivables a system account, because every sales invoice debits it unless
 * the customer names an account of its own. `ensureSystemAccounts()` provisions missing system accounts by
 * *creating* them, and its code-collision helper appends a suffix — so run against a company that already has
 * `1130 Trade Receivables` from the old template, it would create `1130-1` beside it. Two receivable accounts,
 * one of them the one every future invoice posts to, and the other holding the history. That is the outcome
 * this migration exists to prevent.
 *
 * WHY THIS MIGRATION MAY NAME CODE 1130 WHEN THE RUNTIME MUST NOT
 * --------------------------------------------------------------
 * Resolving the receivable account at posting time by code would be wrong: a company may renumber its chart
 * freely, which is the whole reason system keys exist. Here the code is not an assumption about the present —
 * it is a statement about the past. Template `2026.02-lk-sme-1` defined `1130` as Trade Receivables, so for
 * accounts stamped with that template version, `1130` *is* that account. The guard on `template_version` is
 * what makes the claim true rather than hopeful.
 *
 * IDEMPOTENT, AND NARROW
 * ----------------------
 * Five conditions, each closing a way this could go wrong:
 *
 *   - `system_key IS NULL` — re-running changes nothing, and an account already carrying a different key is
 *     never overwritten.
 *   - `template_version` matches the old template — a hand-made `1130` in a company that never used the
 *     starter chart is left alone, because nobody promised what it means.
 *   - `type = 'asset'` — a receivable is an asset. If a company reclassified it, this migration is not the
 *     place to argue.
 *   - `deleted_at IS NULL` — a soft-deleted account is not the company's receivable account.
 *   - no sibling in the same company already holds the key — belt and braces with
 *     `accounts_company_system_key_unique`, but it fails as a clean no-op rather than a constraint violation.
 *
 * Nothing is renamed, renumbered, or created. Account ids are untouched, so every customer's
 * `receivable_account_id` and every posted journal line still points where it did.
 *
 * `template_version` is deliberately left at `2026.02-lk-sme-1` on the stamped rows. Those accounts *were*
 * created from that template; rewriting the field would falsify their history to record a migration.
 *
 * TWO THINGS THIS MIGRATION LEARNED THE HARD WAY
 * ---------------------------------------------
 * **It must set `is_system` as well as `system_key`.** `accounts_system_key_check` asserts
 * `(system_key IS NOT NULL) <= is_system` — a keyed account must be marked a system account. Setting only the
 * key fails the constraint. That is the constraint doing its job: `is_system` is what makes
 * `ChartOfAccountsService` refuse to delete, rename or reclassify the account, and an AR account every invoice
 * posts to should have that protection. Fresh provisioning gets it automatically, because
 * `ChartOfAccountsService::create()` derives `is_system` from the presence of a system key.
 *
 * **It must bypass row level security explicitly.** Migrations run as `asids_app`, which is `NOBYPASSRLS`, and
 * `accounts` is FORCED — so with no tenant published to the session, a data migration sees *zero* rows and
 * reports success. The first version of this migration did exactly that: `UPDATE 0`, migration DONE, nothing
 * stamped. Any future data migration touching a tenant-scoped table has the same trap waiting, and silence is
 * indistinguishable from having nothing to do. Two assertions below refuse to let that outcome pass quietly:
 * `assertBypassEffective()` proves the suspension took, and `assertNothingLeftBehind()` proves no company the
 * migration was written for was missed.
 *
 * The suspension itself goes through `RowLevelSecurity::bypass()` rather than a local copy of it. That helper
 * is deliberately the platform's single greppable spelling of "protection off here" — a migration quietly
 * calling `set_config` by hand is invisible to anyone auditing for exactly that.
 */
return new class extends Migration
{
    private const string PREVIOUS_TEMPLATE_VERSION = '2026.02-lk-sme-1';

    private const string TRADE_RECEIVABLES = 'trade_receivables';

    public function up(): void
    {
        // Every tenant at once, which is why this is raw SQL rather than an Eloquent update: the tenant scope
        // would restrict it to whichever tenant happened to be active, and a migration has no tenant.
        //
        // Which is exactly why the bypass is required. `asids_app` is NOBYPASSRLS and `accounts` is FORCED, so
        // without this the statement matches nothing and the migration reports success having done nothing.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            $stamped = DB::update(<<<'SQL'
            UPDATE accounts AS a
               SET system_key = ?,
                   -- Required by `accounts_system_key_check`: a keyed account must be a system account. It is
                   -- also the protection that stops this account being deleted or reclassified from now on.
                   is_system = true,
                   updated_at = now()
             WHERE a.system_key IS NULL
               AND a.code = '1130'
               AND a.type = 'asset'
               AND a.template_version = ?
               AND a.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM accounts AS sibling
                    WHERE sibling.company_id = a.company_id
                      AND sibling.system_key = ?
                      AND sibling.deleted_at IS NULL
               )
        SQL, [self::TRADE_RECEIVABLES, self::PREVIOUS_TEMPLATE_VERSION, self::TRADE_RECEIVABLES]);

            $this->assertNothingLeftBehind($stamped);
        });
    }

    public function down(): void
    {
        // Only the rows this migration could have created, identified the same way. An account that carried
        // the key for another reason — provisioned fresh from template 2026.08-lk-sme-2, say — is left alone,
        // because rolling back this migration should not un-provision a company that never needed it.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            DB::update(<<<'SQL'
                UPDATE accounts
                   SET system_key = NULL,
                       -- Cleared together, or `accounts_system_key_check` is satisfied but the account is left
                       -- marked a system account and therefore undeletable for no stated reason.
                       is_system = false,
                       updated_at = now()
                 WHERE system_key = ?
                   AND code = '1130'
                   AND template_version = ?
                   AND deleted_at IS NULL
            SQL, [self::TRADE_RECEIVABLES, self::PREVIOUS_TEMPLATE_VERSION]);
        });
    }

    /**
     * Refuses to proceed unless the bypass this migration depends on is actually in force.
     *
     * The direct guard against the trap described above. Without it, `asids_app` — NOBYPASSRLS, no tenant
     * published — matches zero rows on a FORCED table and PostgreSQL reports `UPDATE 0` as a success. The
     * statement, its verification, and the operator's transcript would all agree that nothing needed doing.
     *
     * `asids_rls_bypassed()` is created by the Tenancy migration that runs long before this one, so it can be
     * relied on to exist.
     */
    private function assertBypassEffective(): void
    {
        // Cast in SQL rather than in PHP: PDO's pgsql driver has returned booleans as `'f'` strings, and
        // `(bool) 'f'` is `true` — the check would pass in exactly the case it exists to catch.
        if ((int) DB::scalar('SELECT asids_rls_bypassed()::int') !== 1) {
            throw new RuntimeException(
                'Row level security is still in force. This migration would silently stamp nothing, '
                .'because a NOBYPASSRLS role sees no rows on a FORCED table with no tenant published.'
            );
        }
    }

    /**
     * Refuses to finish while a company this migration was written for still has an unstamped account.
     *
     * The invariant, asserted rather than assumed: after this runs, no company provisioned from the old
     * template may still hold a keyless `1130` and no trade receivables account. A partial update — one row
     * skipped by a constraint, a row written by a concurrent session between the UPDATE and now — would
     * otherwise leave a company whose first sales invoice fails at issue time, months later, for a reason
     * nothing recorded.
     *
     * Stamping zero rows is not itself an error: a database with no legacy companies, or one where this has
     * already run, has nothing to do. That is why the check is on what remains, not on what was touched.
     */
    private function assertNothingLeftBehind(int $stamped): void
    {
        $remaining = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM accounts AS a
             WHERE a.system_key IS NULL
               AND a.code = '1130'
               AND a.type = 'asset'
               AND a.template_version = ?
               AND a.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM accounts AS sibling
                    WHERE sibling.company_id = a.company_id
                      AND sibling.system_key = ?
                      AND sibling.deleted_at IS NULL
               )
        SQL, [self::PREVIOUS_TEMPLATE_VERSION, self::TRADE_RECEIVABLES]);

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                'Stamped %d trade receivables account(s), but %d legacy account(s) remain unstamped. '
                .'Those companies would have a second receivables account created beside the first.',
                $stamped,
                $remaining,
            ));
        }
    }
};
