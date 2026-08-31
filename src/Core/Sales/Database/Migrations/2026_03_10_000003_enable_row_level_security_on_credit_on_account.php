<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the held-credit tables — ADR 0016 §B.
 *
 * A separate migration, and each table gets its own policy, for the same reason the receipt RLS migration is
 * separate and per-table: row level security is not transitive. A policy on `receipt_held_credits` says nothing
 * about a query against `credit_applications`, which is exactly why each carries its own `tenant_id`. Copied
 * verbatim from `2026_03_08_000003_enable_row_level_security_on_customer_receipts.php`.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'receipt_held_credits',
        'credit_applications',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
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
