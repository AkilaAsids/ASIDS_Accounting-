<?php

declare(strict_types=1);

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives companies provisioned before Wave 7 the Trade Payables system key.
 *
 * Phase 5 Wave 7 made Trade Payables a system account, because every posted bill credits it, resolved by
 * key rather than by code. `ensureSystemAccounts()` provisions a missing system account by *creating* one,
 * and its code-collision helper appends a suffix — so run against a company that already has a keyless
 * `2110 Trade Payables` from an earlier template, it would create `2110-1` beside it. Two payable accounts,
 * one taking every future bill and the other holding the history, and nothing reporting it. That is the
 * outcome this migration exists to prevent.
 *
 * A near-verbatim mirror of `2026_03_05_000003_stamp_trade_receivables_system_key.php` with `2110`/Liability
 * in place of `1130`/Asset, and one deliberate departure below.
 *
 * WHY THIS MIGRATION MAY NAME CODE 2110 WHEN THE RUNTIME MUST NOT
 * --------------------------------------------------------------
 * Resolving the payable account at posting time by code would be wrong: a company may renumber its chart
 * freely, which is the whole reason system keys exist. Here the code is not an assumption about the present —
 * it is a statement about the past. Both prior templates defined `2110` as Trade Payables, so for accounts
 * stamped with either version, `2110` *is* that account. The guard on `template_version` is what makes the
 * claim true rather than hopeful.
 *
 * TWO PRIOR TEMPLATE VERSIONS, NOT ONE
 * ------------------------------------
 * Unlike Trade Receivables — which the current template already keyed at provisioning, so only
 * `2026.02-lk-sme-1` needed the backfill — neither `2026.02-lk-sme-1` nor `2026.08-lk-sme-2` ever stamped
 * `2110`. So this backfill covers *both* prior versions. Only the new template `2026.09-lk-sme-3` keys it at
 * provisioning.
 *
 * IDEMPOTENT, AND NARROW
 * ----------------------
 * Each condition closes a way this could go wrong: `system_key IS NULL` (re-running changes nothing, and an
 * account already carrying a key is never overwritten), `template_version` matches a prior template (a
 * hand-made `2110` in a company that never used the starter chart is left alone), `type = 'liability'` (a
 * payable is a liability; if a company reclassified it, this migration is not the place to argue),
 * `deleted_at IS NULL` (a soft-deleted account is not the company's payable account), and no sibling already
 * holds the key (belt and braces with `accounts_company_system_key_unique`, failing as a clean no-op).
 *
 * Nothing is renamed, renumbered, or created. Account ids are untouched, and `template_version` is left at
 * the version that created the stamped rows — rewriting it would falsify their history to record a migration
 * that only stamped a key.
 *
 * TWO THINGS THE RECEIVABLES MIGRATION LEARNED THE HARD WAY, REPRODUCED HERE
 * -------------------------------------------------------------------------
 * **It must set `is_system` as well as `system_key`.** `accounts_system_key_check` asserts
 * `(system_key IS NOT NULL) <= is_system` — setting only the key fails the constraint, and `is_system` is
 * what makes `ChartOfAccountsService` refuse to delete, rename or reclassify the account.
 *
 * **It must bypass row level security explicitly.** Migrations run as `asids_app`, which is `NOBYPASSRLS`,
 * and `accounts` is FORCED — so with no tenant published to the session, a data migration sees *zero* rows
 * and reports success. `assertBypassEffective()` proves the suspension took, and `assertNothingLeftBehind()`
 * proves no company the migration was written for was missed. The suspension goes through
 * `RowLevelSecurity::bypass()` rather than a local copy — the platform's single greppable spelling of
 * "protection off here".
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array PREVIOUS_TEMPLATE_VERSIONS = ['2026.02-lk-sme-1', '2026.08-lk-sme-2'];

    private const string TRADE_PAYABLES = 'trade_payables';

    public function up(): void
    {
        // Every tenant at once, which is why this is raw SQL rather than an Eloquent update: the tenant scope
        // would restrict it to whichever tenant happened to be active, and a migration has no tenant.
        //
        // Which is exactly why the bypass is required. `asids_app` is NOBYPASSRLS and `accounts` is FORCED, so
        // without this the statement matches nothing and the migration reports success having done nothing.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            $placeholders = implode(', ', array_fill(0, count(self::PREVIOUS_TEMPLATE_VERSIONS), '?'));

            $stamped = DB::update(<<<SQL
            UPDATE accounts AS a
               SET system_key = ?,
                   -- Required by `accounts_system_key_check`: a keyed account must be a system account. It is
                   -- also the protection that stops this account being deleted or reclassified from now on.
                   is_system = true,
                   updated_at = now()
             WHERE a.system_key IS NULL
               AND a.code = '2110'
               AND a.type = 'liability'
               AND a.template_version IN ($placeholders)
               AND a.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM accounts AS sibling
                    WHERE sibling.company_id = a.company_id
                      AND sibling.system_key = ?
                      AND sibling.deleted_at IS NULL
               )
        SQL, [self::TRADE_PAYABLES, ...self::PREVIOUS_TEMPLATE_VERSIONS, self::TRADE_PAYABLES]);

            $this->assertNothingLeftBehind($stamped);
        });
    }

    public function down(): void
    {
        // Only the rows this migration could have created, identified the same way. An account that carried
        // the key for another reason — provisioned fresh from template 2026.09-lk-sme-3, say — is left alone,
        // because rolling back this migration should not un-provision a company that never needed it.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            $placeholders = implode(', ', array_fill(0, count(self::PREVIOUS_TEMPLATE_VERSIONS), '?'));

            DB::update(<<<SQL
                UPDATE accounts
                   SET system_key = NULL,
                       -- Cleared together, or `accounts_system_key_check` is satisfied but the account is left
                       -- marked a system account and therefore undeletable for no stated reason.
                       is_system = false,
                       updated_at = now()
                 WHERE system_key = ?
                   AND code = '2110'
                   AND template_version IN ($placeholders)
                   AND deleted_at IS NULL
            SQL, [self::TRADE_PAYABLES, ...self::PREVIOUS_TEMPLATE_VERSIONS]);
        });
    }

    /**
     * Refuses to proceed unless the bypass this migration depends on is actually in force.
     *
     * Without it, `asids_app` — NOBYPASSRLS, no tenant published — matches zero rows on a FORCED table and
     * PostgreSQL reports `UPDATE 0` as a success, so the statement, its verification, and the operator's
     * transcript would all agree that nothing needed doing.
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
     * After this runs, no company provisioned from a prior template may still hold a keyless `2110` and no
     * trade payables account. A partial update would otherwise leave a company whose first bill fails at post
     * time, months later, for a reason nothing recorded.
     *
     * Stamping zero rows is not itself an error: a database with no legacy companies, or one where this has
     * already run, has nothing to do. That is why the check is on what remains, not on what was touched.
     */
    private function assertNothingLeftBehind(int $stamped): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::PREVIOUS_TEMPLATE_VERSIONS), '?'));

        $remaining = (int) DB::scalar(<<<SQL
            SELECT count(*)
              FROM accounts AS a
             WHERE a.system_key IS NULL
               AND a.code = '2110'
               AND a.type = 'liability'
               AND a.template_version IN ($placeholders)
               AND a.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM accounts AS sibling
                    WHERE sibling.company_id = a.company_id
                      AND sibling.system_key = ?
                      AND sibling.deleted_at IS NULL
               )
        SQL, [...self::PREVIOUS_TEMPLATE_VERSIONS, self::TRADE_PAYABLES]);

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                'Stamped %d trade payables account(s), but %d legacy account(s) remain unstamped. '
                .'Those companies would have a second payables account created beside the first.',
                $stamped,
                $remaining,
            ));
        }
    }
};
