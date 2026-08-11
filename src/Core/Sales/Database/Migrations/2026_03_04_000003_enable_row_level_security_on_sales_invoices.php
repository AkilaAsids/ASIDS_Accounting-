<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the invoice tables.
 *
 * A third RLS migration in the module rather than an edit to either of the first two, and for the same reason
 * as the second: those have already run on every database that exists. Adding a table to an applied
 * migration's list would leave the policy missing everywhere it had already been applied, and the gap would
 * be invisible — reads would simply return other tenants' rows.
 *
 * `sales_invoice_lines` gets its own policy rather than relying on its parent's. Row level security is not
 * transitive: a policy on `sales_invoices` says nothing about a query against the lines table, and a report
 * that joined from lines upward would be unprotected. Every tenant-scoped table carries its own
 * `tenant_id` and its own policy, which is why the column is denormalised onto the child in the first place.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'sales_invoices',
        'sales_invoice_lines',
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
