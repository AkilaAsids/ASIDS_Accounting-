<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the bill tables.
 *
 * A new RLS migration rather than an edit to Purchasing's first one, for the same reason Sales split its
 * third: the earlier migration has already run on every database that exists, and adding a table to an
 * applied migration's list would leave the policy missing everywhere it had already been applied — an
 * invisible gap through which reads return other tenants' rows.
 *
 * `bill_lines` gets its own policy rather than relying on its parent's. Row level security is not transitive:
 * a policy on `bills` says nothing about a query against the lines table. FORCE is what makes the policy
 * apply to the table's owner too — without it CI passes vacuously (mirror the Purchasing RLS migration).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'bills',
        'bill_lines',
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
