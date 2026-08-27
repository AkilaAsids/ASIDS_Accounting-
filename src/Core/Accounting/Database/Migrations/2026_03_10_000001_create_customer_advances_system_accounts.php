<?php

declare(strict_types=1);

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates `2180 Customer Advances` for every company built before it existed — ADR 0016 §A, Stage 1.
 *
 * WHY THIS IS A CREATE, NOT A STAMP
 * ---------------------------------
 * Trade Receivables already existed as `1130` in the earlier template, so `2026_03_05_000003` only had to
 * *stamp* a system key onto an account that was already there. Customer Advances has never existed in any
 * template, so there is no row to stamp: every company built before this wave needs the account *created*.
 * That is the one material difference from the Trade Receivables precedent (Gate-2 decision (a)).
 *
 * A STATEMENT ABOUT THE PAST, IN RAW SQL
 * --------------------------------------
 * This creates the account directly rather than calling `ChartTemplateService::ensureSystemAccounts()`. The
 * service is the right path for *new* provisioning, but a migration is a fixed statement about the companies
 * that exist now — coupling it to a service that may evolve would let a future change to that service silently
 * rewrite what this migration does when it is re-run against a fresh database. The `2026_03_05_000003`
 * reasoning, applied to a create.
 *
 * It still borrows that migration's two hard-won disciplines:
 *
 *   1. **Bypass row level security explicitly.** `asids_app` is NOBYPASSRLS and `accounts` is FORCED, so a
 *      migration with no tenant published sees zero rows and would report success having created nothing.
 *      `assertBypassEffective()` proves the suspension took.
 *   2. **Assert nothing is left behind.** After running, every company must hold exactly one active
 *      `customer_advances` account, or a company whose first overpayment would otherwise fail months later is
 *      caught now — `assertNothingLeftBehind()`.
 *
 * IS_SYSTEM AND SYSTEM_KEY TOGETHER
 * ---------------------------------
 * `accounts_system_key_check` asserts `(system_key IS NOT NULL) <= is_system`: a keyed account must be marked
 * a system account. Writing the key without the flag fails the constraint — the exact trap `2026_03_05_000003`
 * documents — so both are written in the same INSERT, alongside `normal_balance = 'credit'` (required for a
 * liability by `accounts_normal_balance_matches_type_check`).
 *
 * CODE COLLISIONS
 * ---------------
 * `2180` is the template code, but a company that renumbered may already use it for something else. The account
 * is resolved by key, never by code, so the code is free to vary: where `2180` is taken, the next free
 * `2180-1`, `2180-2`, … is used, mirroring `ChartTemplateService::availableCode()`. `template_version` is left
 * null: these rows were created by this migration, not applied from a template, and recording a version would
 * falsify that history.
 */
return new class extends Migration
{
    private const string CUSTOMER_ADVANCES = 'customer_advances';

    public function up(): void
    {
        // Every tenant at once, which is why the bypass is required: a migration has no tenant, and without the
        // suspension a NOBYPASSRLS role sees no rows on a FORCED table and creates nothing.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            /** @var list<object{id: string, tenant_id: string}> $companies */
            $companies = DB::table('companies')->select('id', 'tenant_id')->get()->all();

            $created = 0;

            foreach ($companies as $company) {
                $alreadyHas = DB::table('accounts')
                    ->where('company_id', $company->id)
                    ->where('system_key', self::CUSTOMER_ADVANCES)
                    ->whereNull('deleted_at')
                    ->exists();

                // Skip a company that already holds the key — a fresh company provisioned from the new
                // template, or one already backfilled by an earlier run. A create-not-stamp migration that
                // did not skip would collide at `accounts_company_system_key_unique`.
                if ($alreadyHas) {
                    continue;
                }

                $parentId = DB::table('accounts')
                    ->where('company_id', $company->id)
                    ->where('code', '2100')
                    ->whereNull('deleted_at')
                    ->value('id');

                DB::table('accounts')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    // Parentless is acceptable — it is exactly what `ensureSystemAccounts()` produces for a
                    // company with no Current-Liabilities heading to hang it under.
                    'parent_id' => $parentId,
                    'code' => $this->availableCode($company->id),
                    'name' => 'Customer Advances',
                    'type' => 'liability',
                    // Required for a liability by `accounts_normal_balance_matches_type_check`.
                    'normal_balance' => 'credit',
                    'is_postable' => true,
                    // Written together with `system_key`, or `accounts_system_key_check` refuses the row.
                    'is_system' => true,
                    'system_key' => self::CUSTOMER_ADVANCES,
                    'is_active' => true,
                    'template_version' => null,
                    'sort_order' => 2180,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created++;
            }

            $this->assertNothingLeftBehind($created);
        });
    }

    public function down(): void
    {
        // Only the rows this migration could have created: keyed `customer_advances`, marked system, carrying
        // no template version, and — the guard that matters — holding no postings. An account that has already
        // received an overpayment's credit is history and is left alone; the restrict FK on `journal_lines`
        // would refuse its deletion in any case.
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            DB::table('accounts')
                ->where('system_key', self::CUSTOMER_ADVANCES)
                ->where('is_system', true)
                ->whereNull('template_version')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('journal_lines')
                        ->whereColumn('journal_lines.account_id', 'accounts.id');
                })
                ->delete();
        });
    }

    /**
     * The template's preferred `2180`, or the first free variation of it for a company that already uses it.
     *
     * Mirrors `ChartTemplateService::availableCode()`: the account is resolved by key, so its code may vary
     * without breaking anything, and taking the next free number beats refusing to provision the company.
     */
    private function availableCode(string $companyId): string
    {
        $taken = static fn (string $code): bool => DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereRaw('lower(code) = ?', [strtolower($code)])
            ->whereNull('deleted_at')
            ->exists();

        if (! $taken('2180')) {
            return '2180';
        }

        for ($suffix = 1; $suffix < 100; $suffix++) {
            $candidate = '2180-'.$suffix;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find a free account code near “2180” for company '.$companyId.'.');
    }

    /**
     * Refuses to proceed unless the bypass this migration depends on is actually in force.
     *
     * Without it, `asids_app` — NOBYPASSRLS, no tenant published — matches zero rows on a FORCED table, and
     * PostgreSQL reports the create as a success having done nothing. `asids_rls_bypassed()` is created by the
     * Tenancy migration that runs long before this one.
     */
    private function assertBypassEffective(): void
    {
        // Cast in SQL rather than PHP: PDO's pgsql driver has returned booleans as `'f'` strings, and
        // `(bool) 'f'` is `true` — the check would pass in exactly the case it exists to catch.
        if ((int) DB::scalar('SELECT asids_rls_bypassed()::int') !== 1) {
            throw new RuntimeException(
                'Row level security is still in force. This migration would silently create nothing, '
                .'because a NOBYPASSRLS role sees no rows on a FORCED table with no tenant published.'
            );
        }
    }

    /**
     * Refuses to finish while any company still lacks an active Customer Advances account.
     *
     * The invariant, asserted rather than assumed: after this runs, no company may be without the account its
     * first overpayment will need. A create skipped by a constraint, or a company written by a concurrent
     * session between the loop and now, would otherwise leave a company that fails at record time months later
     * for a reason nothing recorded. Creating zero rows is not itself an error — a database with no legacy
     * companies, or one already backfilled, has nothing to do — which is why the check is on what remains.
     */
    private function assertNothingLeftBehind(int $created): void
    {
        $remaining = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM companies AS c
             WHERE NOT EXISTS (
                 SELECT 1
                   FROM accounts AS a
                  WHERE a.company_id = c.id
                    AND a.system_key = ?
                    AND a.deleted_at IS NULL
             )
        SQL, [self::CUSTOMER_ADVANCES]);

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                'Created %d Customer Advances account(s), but %d company/companies still hold none. '
                .'Their first overpayment would fail to record for a reason nothing here would explain.',
                $created,
                $remaining,
            ));
        }
    }
};
