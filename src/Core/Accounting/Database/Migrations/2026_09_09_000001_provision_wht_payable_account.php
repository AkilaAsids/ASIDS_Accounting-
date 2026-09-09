<?php

declare(strict_types=1);

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives every company built before Wave 8b a `WHT Payable` (`2180`) system account — Stage 2 of Wave 8b
 * (ADR 0021 §A6).
 *
 * CREATE, NOT STAMP — the one part of 8b with no faithful precedent
 * ----------------------------------------------------------------
 * The trade-receivables/payables backfills *stamp* a `system_key` onto an existing `1130`/`2110` — an account
 * every prior template already created. `2180 WHT Payable` exists in NO prior template, so there is nothing to
 * stamp: this migration must CREATE the account in each existing company, or that company's first withholding
 * payment fails to post (the credit has nowhere to land).
 *
 * The RLS scaffold is reproduced verbatim from the stamp precedents (`2026_09_02_000001_stamp_trade_payables…`),
 * because it is the recurring trap: migrations run as `asids_app` (NOBYPASSRLS) and `accounts` is FORCED, so
 * with no tenant published a data migration sees ZERO rows and reports success having created nothing.
 * `assertBypassEffective()` proves the suspension took; `assertNothingLeftBehind()` proves no company was
 * missed. Raw SQL only — `RowLevelSecurity::bypass()` suspends the database policy but NOT Eloquent's
 * `TenantScope`, and `BelongsToTenant::creating` throws `NoActiveTenant` in a tenant-less migration.
 *
 * It diverges from the stamps only in the operation:
 *   - Idempotency is keyed on the account's ABSENCE ("this company has no live wht_payable"), which covers all
 *     prior template versions (`-1`/`-2`/`-3`) and is re-run-safe with no `template_version` guard.
 *   - A code-collision guard (reproducing `ChartTemplateService::availableCode`) takes `2180-1`, `2180-2`, … if
 *     a hand-numbered chart already uses `2180`.
 *   - The leaf attaches to the company's `2100 Current Liabilities` heading when resolvable, else `parent_id`
 *     NULL — a flat but valid system account, exactly as `ensureSystemAccounts()` creates required accounts.
 */
return new class extends Migration
{
    private const string WHT_PAYABLE = 'wht_payable';

    public function up(): void
    {
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            /** @var list<object{id: string, tenant_id: string}> $companies */
            $companies = DB::select('SELECT id, tenant_id FROM companies');

            foreach ($companies as $company) {
                $hasWht = DB::scalar(
                    'SELECT count(*) FROM accounts WHERE company_id = ? AND system_key = ? AND deleted_at IS NULL',
                    [$company->id, self::WHT_PAYABLE],
                ) > 0;

                if ($hasWht) {
                    continue;
                }

                $code = $this->availableCode((string) $company->id);
                $parentId = DB::scalar(
                    "SELECT id FROM accounts WHERE company_id = ? AND code = '2100' AND type = 'liability' AND deleted_at IS NULL LIMIT 1",
                    [$company->id],
                );

                DB::table('accounts')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'parent_id' => $parentId,
                    'code' => $code,
                    'name' => 'WHT Payable',
                    'type' => 'liability',
                    // accounts_normal_balance_matches_type_check: a liability is credit-normal.
                    'normal_balance' => 'credit',
                    'is_postable' => true,
                    // accounts_system_key_check: a keyed account must be a system account.
                    'is_system' => true,
                    'system_key' => self::WHT_PAYABLE,
                    // accounts_archived_check: is_active = (archived_at IS NULL).
                    'is_active' => true,
                    'archived_at' => null,
                    // Created by a migration, not a template — as ensureSystemAccounts does.
                    'template_version' => null,
                    'sort_order' => 2180,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->assertNothingLeftBehind();
        });
    }

    public function down(): void
    {
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            // Only the accounts this migration could have created, and only while they carry no ledger history
            // — a system account with postings is never hard-deleted, and the restrict-on-delete FK from
            // journal_lines would refuse it anyway.
            DB::delete(<<<'SQL'
                DELETE FROM accounts AS a
                 WHERE a.system_key = ?
                   AND a.is_system = true
                   AND a.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM journal_lines AS jl WHERE jl.account_id = a.id)
            SQL, [self::WHT_PAYABLE]);
        });
    }

    /**
     * The preferred code `2180`, or the first free `2180-N` — reproducing `ChartTemplateService::availableCode`
     * so a hand-numbered chart that already uses `2180` does not hit `accounts_company_code_unique` as a raw
     * constraint error.
     */
    private function availableCode(string $companyId): string
    {
        $taken = fn (string $candidate): bool => DB::scalar(
            'SELECT count(*) FROM accounts WHERE company_id = ? AND lower(code) = ? AND deleted_at IS NULL',
            [$companyId, strtolower($candidate)],
        ) > 0;

        if (! $taken('2180')) {
            return '2180';
        }

        for ($suffix = 1; $suffix < 100; $suffix++) {
            $candidate = '2180-'.$suffix;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Could not find a free account code near "2180" for company %s.', $companyId));
    }

    /**
     * Refuse to proceed unless the bypass is in force — mirror the stamp precedents. Cast in SQL because PDO
     * can return the boolean as `'f'`, and `(bool) 'f'` is `true`.
     */
    private function assertBypassEffective(): void
    {
        if ((int) DB::scalar('SELECT asids_rls_bypassed()::int') !== 1) {
            throw new RuntimeException(
                'Row level security is still in force. This migration would silently create nothing, because a '
                .'NOBYPASSRLS role sees no rows on a FORCED table with no tenant published.'
            );
        }
    }

    /**
     * Refuse to finish while any company still lacks a live WHT payable account — a partial run would leave a
     * company whose first withholding payment fails at post time, months later, for a reason nothing recorded.
     */
    private function assertNothingLeftBehind(): void
    {
        $remaining = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM companies AS c
             WHERE NOT EXISTS (
                 SELECT 1 FROM accounts AS a
                  WHERE a.company_id = c.id
                    AND a.system_key = ?
                    AND a.deleted_at IS NULL
             )
        SQL, [self::WHT_PAYABLE]);

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                '%d company(ies) still have no WHT Payable account after the backfill. Their first withholding '
                .'payment would fail to post.',
                $remaining,
            ));
        }
    }
};
