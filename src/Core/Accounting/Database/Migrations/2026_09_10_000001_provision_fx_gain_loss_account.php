<?php

declare(strict_types=1);

use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives every company built before Wave 9a a `Foreign Exchange Gain/(Loss)` (`4910`) system account — Stage 3
 * of Wave 9a (ADR 0022 §A6).
 *
 * CREATE, NOT STAMP — `4910` exists in no prior template, so there is nothing to stamp: this creates the
 * account in each existing company, or that company's first foreign settlement fails to post (the realised FX
 * has nowhere to land). A verbatim reproduction of the Wave 8b WHT Payable backfill
 * (`2026_09_09_000001_provision_wht_payable_account.php`) with `fx_gain_loss`/`4910`/Income/parent `4000` in
 * place of `wht_payable`/`2180`/Liability/`2100`.
 *
 * The RLS scaffold is the recurring trap: migrations run as `asids_app` (NOBYPASSRLS) and `accounts` is
 * FORCED, so with no tenant published a data migration sees ZERO rows and reports success. `bypass()` +
 * `assertBypassEffective()` + `assertNothingLeftBehind()` guard it; raw SQL only (bypass suspends the DB
 * policy, not Eloquent's TenantScope). Idempotent by key-absence; a hand-numbered `4910` collision takes the
 * next free `4910-N`; the leaf attaches to `4000 Income` when resolvable, else `parent_id` NULL.
 */
return new class extends Migration
{
    private const string FX_GAIN_LOSS = 'fx_gain_loss';

    public function up(): void
    {
        RowLevelSecurity::bypass(function (): void {
            $this->assertBypassEffective();

            /** @var list<object{id: string, tenant_id: string}> $companies */
            $companies = DB::select('SELECT id, tenant_id FROM companies');

            foreach ($companies as $company) {
                $hasFx = DB::scalar(
                    'SELECT count(*) FROM accounts WHERE company_id = ? AND system_key = ? AND deleted_at IS NULL',
                    [$company->id, self::FX_GAIN_LOSS],
                ) > 0;

                if ($hasFx) {
                    continue;
                }

                $code = $this->availableCode((string) $company->id);
                $parentId = DB::scalar(
                    "SELECT id FROM accounts WHERE company_id = ? AND code = '4000' AND type = 'income' AND deleted_at IS NULL LIMIT 1",
                    [$company->id],
                );

                DB::table('accounts')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'parent_id' => $parentId,
                    'code' => $code,
                    'name' => 'Foreign Exchange Gain/(Loss)',
                    'type' => 'income',
                    // accounts_normal_balance_matches_type_check: income is credit-normal.
                    'normal_balance' => 'credit',
                    'is_postable' => true,
                    'is_system' => true,
                    'system_key' => self::FX_GAIN_LOSS,
                    'is_active' => true,
                    'archived_at' => null,
                    'template_version' => null,
                    'sort_order' => 4910,
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

            DB::delete(<<<'SQL'
                DELETE FROM accounts AS a
                 WHERE a.system_key = ?
                   AND a.is_system = true
                   AND a.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM journal_lines AS jl WHERE jl.account_id = a.id)
            SQL, [self::FX_GAIN_LOSS]);
        });
    }

    private function availableCode(string $companyId): string
    {
        $taken = fn (string $candidate): bool => DB::scalar(
            'SELECT count(*) FROM accounts WHERE company_id = ? AND lower(code) = ? AND deleted_at IS NULL',
            [$companyId, strtolower($candidate)],
        ) > 0;

        if (! $taken('4910')) {
            return '4910';
        }

        for ($suffix = 1; $suffix < 100; $suffix++) {
            $candidate = '4910-'.$suffix;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Could not find a free account code near "4910" for company %s.', $companyId));
    }

    private function assertBypassEffective(): void
    {
        if ((int) DB::scalar('SELECT asids_rls_bypassed()::int') !== 1) {
            throw new RuntimeException(
                'Row level security is still in force. This migration would silently create nothing, because a '
                .'NOBYPASSRLS role sees no rows on a FORCED table with no tenant published.'
            );
        }
    }

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
        SQL, [self::FX_GAIN_LOSS]);

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                '%d company(ies) still have no Foreign Exchange Gain/(Loss) account after the backfill. Their '
                .'first foreign settlement would fail to post.',
                $remaining,
            ));
        }
    }
};
