<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for `tax_codes`.
 *
 * A separate migration rather than an edit to `enable_row_level_security_on_sales`, and that is not a
 * stylistic choice: that migration has already run on every database that exists. Adding a table to
 * its `TABLES` list would leave the policy missing everywhere it had already been applied, and the
 * gap would be invisible — reads would simply return other tenants' rows.
 *
 * The policy body is identical to the module's first one, reusing `asids_current_tenant_id()` and
 * `asids_rls_bypassed()` from Phase 1 so there is one definition of "the active tenant" and one bypass
 * switch platform-wide.
 *
 * FORCE is what extends the policy to the table's owner. CI has already demonstrated what its absence
 * costs: for three runs the suite connected as a superuser, `RowLevelSecurityTest` skipped its eleven
 * assertions rather than passing vacuously, and tenant isolation was verified by nothing at all.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'tax_codes',
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
