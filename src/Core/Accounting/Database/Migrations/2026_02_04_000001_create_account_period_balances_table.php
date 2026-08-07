<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account, per-period movement totals.
 *
 * A cache with a transaction around it. `journal_lines` remains the only thing that is *true*; every
 * row here is derivable from it, and `asids:ledger-verify` exists to prove that they still agree.
 *
 * WHY MAINTAIN IT AT ALL
 * ----------------------
 * A trial balance is `SUM(debit), SUM(credit) GROUP BY account` over a date range. That is correct
 * and simple and gets slower every month a customer trades. On seven-year retention an SME crosses
 * the point where every report scans years of history, and retrofitting aggregates onto a live
 * ledger is considerably harder than maintaining them from the first entry.
 *
 * WHAT IT STORES, AND WHAT IT DOES NOT
 * ------------------------------------
 * Movements only — the debits and credits that occurred *within* the period. Not the closing
 * balance. A closing balance is the opening balance plus the period's movement, and storing it would
 * mean every correction to an old period rewriting every row after it. Callers that want a running
 * balance accumulate movements in period order, which is a scan of a small table.
 *
 * Updated inside the posting transaction, never asynchronously. A queued update would leave a window
 * where the ledger and the reports disagree, and nothing would say which was right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_period_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Cascade: if an account or period could ever be removed, its derived totals should go
            // with it rather than linger as orphans. Both are themselves restricted from deletion
            // once they carry history, so in practice this never fires.
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUuid('fiscal_period_id')->constrained('fiscal_periods')->cascadeOnDelete();

            $table->decimal('debit_total', 19, 4)->default(0);
            $table->decimal('credit_total', 19, 4)->default(0);

            // How many lines produced these totals. Not needed to compute anything — it is what makes
            // a drift report useful, because "expected 4 lines, found 5" localises the problem in a
            // way "expected 1200.00, found 1500.00" does not.
            $table->unsignedInteger('line_count')->default(0);

            $table->timestampsTz();

            // The trial balance's query: every account's movements for one period.
            $table->index(['company_id', 'fiscal_period_id'], 'account_period_balances_trial_balance');
            // The account ledger's query: one account across periods, in order.
            $table->index(['company_id', 'account_id'], 'account_period_balances_account_history');
            $table->index(['tenant_id', 'company_id']);
        });

        // One row per account per period. Without this a concurrent posting could insert a second row
        // for the same pair and every report would double-count that account.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX account_period_balances_scope_unique
                ON account_period_balances (account_id, fiscal_period_id)
        SQL);

        DB::statement('ALTER TABLE account_period_balances ADD CONSTRAINT account_period_balances_non_negative_check CHECK (debit_total >= 0 AND credit_total >= 0)');

        DB::statement("COMMENT ON TABLE account_period_balances IS 'Derived movement totals per account per period. journal_lines is the source of truth; asids:ledger-verify proves they agree.'");
        DB::statement("COMMENT ON COLUMN account_period_balances.line_count IS 'Number of lines behind the totals. Exists to localise drift, not to compute anything.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_period_balances');
    }
};
