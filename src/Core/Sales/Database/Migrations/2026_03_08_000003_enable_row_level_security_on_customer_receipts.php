<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the receipt tables.
 *
 * A separate migration rather than an edit to the table migrations, for the same reason the invoice RLS
 * migration is separate: adding a table to an applied migration's list would leave the policy missing
 * everywhere it had already run, and the gap would be invisible — reads would return other tenants' rows.
 *
 * `receipt_allocations` gets its own policy rather than relying on its parent's. Row level security is not
 * transitive: a policy on `customer_receipts` says nothing about a query against the allocations table, which
 * is exactly why the child carries its own `tenant_id`. Copied verbatim from
 * `2026_03_04_000003_enable_row_level_security_on_sales_invoices.php`.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'customer_receipts',
        'receipt_allocations',
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
