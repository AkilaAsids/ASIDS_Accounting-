<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the payment tables (ADR 0020 §A6).
 *
 * A new RLS migration rather than an edit to an applied one, for the reason the bills RLS migration gives
 * (`2026_09_02_000004_enable_row_level_security_on_bills.php:11-18`): adding a table to a migration that has
 * already run would leave the policy missing everywhere it was applied — an invisible read of other tenants'
 * rows.
 *
 * `payment_allocations` gets its own policy rather than relying on its parent's. Row level security is not
 * transitive: a policy on `supplier_payments` says nothing about a query against the allocations table. FORCE
 * is what makes the policy apply to the table's owner too — without it CI passes vacuously.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'supplier_payments',
        'payment_allocations',
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
