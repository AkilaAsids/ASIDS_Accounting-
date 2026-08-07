<?php

declare(strict_types=1);

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts.
 *
 * Hierarchical, because a controller wants three bank accounts to roll up into one "Bank" line on
 * the balance sheet without losing the detail underneath. The hierarchy is presentational: postings
 * go to leaf accounts, and a parent's balance is the sum of its descendants.
 *
 * Two rules here are worth more than the rest put together, and both are about what happens *after*
 * an account has been used:
 *
 *   1. **An account with postings cannot change its type.** Reclassifying an expense as an asset
 *      silently rewrites every historical statement the account has ever appeared on. The books
 *      still balance, so nothing looks wrong — last year's profit simply becomes a different number
 *      than the one that was filed.
 *   2. **An account with postings is never deleted, only archived.** Its history has to stay
 *      resolvable for as long as the retention obligation runs.
 *
 * Both are enforced by the service against the ledger, and the second is backed here by the
 * restrict-on-delete foreign key that `journal_lines` will add in tranche 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // The self-reference is declared here but constrained after the table exists — see
            // below. PostgreSQL will not accept a foreign key pointing at a table whose primary key
            // is being created in the same statement.
            $table->uuid('parent_id')->nullable();

            // What the accountant types and what appears on reports. Unique per company,
            // case-insensitively — "1000" and "1000 " are the same account to every human.
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('type', 24);

            // Denormalised from the type. Stored rather than derived so that reporting SQL can sign
            // a balance without a CASE over five values in every query, and so an index can carry it.
            $table->string('normal_balance', 8);

            // A posting account takes journal lines. A heading exists only to group its children —
            // posting to one is how a chart of accounts turns into a chart of subtotals nobody can
            // reconcile.
            $table->boolean('is_postable')->default(true);

            // System accounts are created by the platform and referenced by machine name: retained
            // earnings, opening balance equity. They cannot be deleted, and their type cannot move.
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 48)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('archived_at')->nullable();

            // Which starter template version this account came from, when it came from one at all.
            // Without it, a corrected template leaves no way to find the companies built on the
            // earlier one.
            $table->string('template_version', 16)->nullable();

            // Presentation order within a parent. Accountants order a chart deliberately — current
            // assets before fixed assets — and alphabetical order by code does not express it.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'company_id', 'type']);
            $table->index(['company_id', 'parent_id', 'sort_order']);
        });

        // Restrict, not cascade: deleting a parent must not silently orphan the accounts rolling up
        // into it, nor take them with it. The service reparents or refuses, so the customer is told
        // which accounts are in the way rather than discovering their absence from a report.
        DB::statement(<<<'SQL'
            ALTER TABLE accounts
                ADD CONSTRAINT accounts_parent_id_foreign
                FOREIGN KEY (parent_id) REFERENCES accounts (id) ON DELETE RESTRICT
        SQL);

        // Case-insensitive uniqueness per company, ignoring soft-deleted rows. An expression index
        // rather than a plain unique, for the same reason company codes use one: "1000" and "1000"
        // differing only in case are the same account on every document they appear on.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_company_code_unique
                ON accounts (company_id, lower(code))
                WHERE deleted_at IS NULL
        SQL);

        // One account per system key per company. `retained_earnings` must resolve to exactly one
        // account or the year-end close has to guess.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX accounts_company_system_key_unique
                ON accounts (company_id, system_key)
                WHERE system_key IS NOT NULL AND deleted_at IS NULL
        SQL);

        $types = implode("', '", AccountType::values());
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('{$types}'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check CHECK (normal_balance IN ('debit', 'credit'))");

        // The normal balance is a function of the type, so the two can never disagree. An asset with
        // a credit normal balance would report every figure with the wrong sign while the books
        // still balanced — the hardest kind of error to notice.
        DB::statement(<<<'SQL'
            ALTER TABLE accounts
                ADD CONSTRAINT accounts_normal_balance_matches_type_check
                CHECK (
                    (type IN ('asset', 'expense') AND normal_balance = 'debit')
                    OR (type IN ('liability', 'equity', 'income') AND normal_balance = 'credit')
                )
        SQL);

        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_archived_check CHECK (is_active = (archived_at IS NULL))');

        // A system account is identified by its key, and a key belongs to a system account.
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_system_key_check CHECK ((system_key IS NOT NULL) <= is_system)');

        // An account cannot be its own parent. Deeper cycles cannot be expressed as a check
        // constraint and are refused by the service, which walks the ancestry.
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_not_own_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');

        DB::statement("COMMENT ON TABLE accounts IS 'The chart of accounts. Hierarchical for presentation; postings go to leaf accounts marked postable.'");
        DB::statement("COMMENT ON COLUMN accounts.template_version IS 'The starter template version this account came from, if any. Lets a corrected template identify affected companies.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
