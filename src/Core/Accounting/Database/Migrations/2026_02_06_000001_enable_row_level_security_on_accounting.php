<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the row level security policies to the accounting tables.
 *
 * Written in the same tranche as the tables it protects, not afterwards. Phase 1's worst defect came
 * from RLS and its enforcement setting disagreeing, and the shape of that mistake is a table that
 * exists for a while before its policy does.
 *
 * All nine are **strict**: `tenant_id` is never null on any of them. Phase 1 needed a nullable-tenant
 * variant for rows that legitimately belong to the platform rather than a customer — a staff account,
 * a role template, a system-scope setting. There is no equivalent here. ASIDS does not keep books, so
 * a ledger row with no workspace is not a platform record, it is corruption.
 *
 * The policies reuse `asids_current_tenant_id()` and `asids_rls_bypassed()` from the Phase 1
 * migration rather than redefining them, so there is one definition of "the active tenant" and one
 * bypass switch for the whole platform.
 */
return new class extends Migration
{
    /**
     * Every accounting table. All carry a non-null `tenant_id`.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'fiscal_years',
        'fiscal_periods',
        'accounts',
        'journals',
        'journal_entries',
        'journal_lines',
        'account_period_balances',
        'document_sequences',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            // FORCE is what makes the policy apply to the table's owner too. Without it a developer
            // or a migration connecting as the schema owner runs with protection off, and every
            // isolation test passes while proving nothing.
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                    FOR ALL
                    USING (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())
                    WITH CHECK (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
