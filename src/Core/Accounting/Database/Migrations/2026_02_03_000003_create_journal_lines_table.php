<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The debits and credits.
 *
 * This table is the ledger. Everything else in the module organises, reports on, or protects it.
 *
 * THE BALANCE RULE IS A DEFERRED CONSTRAINT TRIGGER
 * -------------------------------------------------
 * Debits must equal credits for every entry. That cannot be an ordinary check constraint — the rule
 * is about a *set* of rows, not one row — and it cannot be an immediate trigger either, because
 * lines are inserted one at a time and the first insert would always fail.
 *
 * `DEFERRABLE INITIALLY DEFERRED` runs the check once, at COMMIT, when the whole entry is present.
 * The effect is that no transaction can commit an unbalanced entry through any route at all: not the
 * service, not a console command, not a hand-written UPDATE by someone with database access. Given
 * that an unbalanced ledger is unrecoverable without knowing which side was wrong, that is the right
 * place for the rule to live.
 *
 * TWO COLUMNS RATHER THAN ONE SIGNED AMOUNT
 * -----------------------------------------
 * `debit` and `credit`, each non-negative, exactly one non-zero. A single signed column is less
 * storage and less code; two columns match how the trial balance, the account ledger and every
 * printed report an accountant recognises are expressed, so the storage matches the domain instead
 * of requiring a translation at every read.
 *
 * FOREIGN CURRENCY: THE SHAPE NOW, THE BEHAVIOUR LATER
 * ----------------------------------------------------
 * The three transaction-currency columns are nullable, and NULL is meaningful: the line is in the
 * company's base currency at rate 1. That stores no redundant rows of `LKR / 1.0000` and makes "is
 * this an FX line?" a single `IS NOT NULL`.
 *
 * Two constraints with deliberately different lifespans. The all-or-nothing shape rule is permanent.
 * The NULL-only rule — `journal_lines_single_currency_until_fx_phase` — is what actually enforces
 * "base currency only" for this phase, and the FX phase drops that one constraint and nothing else.
 * Building the columns now avoids backfilling the largest table in the platform on a live tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Cascade from the entry: a draft's lines belong to it and go with it. The entry itself
            // cannot be deleted once posted, so this never removes posted history.
            $table->foreignUuid('journal_entry_id')
                ->constrained('journal_entries')
                ->cascadeOnDelete();

            // Restrict: an account with lines cannot be deleted. This is the database backing for
            // the rule ChartOfAccountsService enforces, and it is what catches the paths the service
            // does not see.
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();

            // The branch dimension. A company's trial balance is the sum across its branches, so
            // this narrows a report rather than partitioning the ledger.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Ordering within the entry, so a document redisplays as it was entered.
            $table->unsignedSmallInteger('line_number');

            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);

            $table->string('description')->nullable();

            // ── Foreign currency: shape only until the FX phase ──────────────────
            $table->char('transaction_currency_code', 3)->nullable();
            $table->decimal('transaction_amount', 19, 4)->nullable();
            $table->decimal('exchange_rate', 19, 10)->nullable();

            $table->timestampsTz();

            // The account ledger's query: one account, ordered by date. Carries the amounts so the
            // report is an index-only scan rather than a heap fetch per line.
            $table->index(['company_id', 'account_id', 'journal_entry_id'], 'journal_lines_account_lookup');
            $table->index(['tenant_id', 'company_id']);
            $table->index(['journal_entry_id', 'line_number']);
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_debit_non_negative_check CHECK (debit >= 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_credit_non_negative_check CHECK (credit >= 0)');

        // Exactly one side carries the amount. A line with both is ambiguous — is it a net movement,
        // or two movements recorded on one row? — and a line with neither is noise that survives
        // every balance check because zero equals zero.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_one_sided_check
                CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))
        SQL);

        // Permanent: the three currency columns are all present or all absent, and a rate is positive.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_currency_columns_check
                CHECK (
                    (transaction_currency_code IS NULL AND transaction_amount IS NULL AND exchange_rate IS NULL)
                    OR (transaction_currency_code IS NOT NULL AND transaction_amount IS NOT NULL AND exchange_rate IS NOT NULL AND exchange_rate > 0)
                )
        SQL);

        // Phase-scoped: this is what makes "base currency only" true rather than intended. The FX
        // phase drops exactly this constraint; the columns and the shape rule above stay.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_single_currency_until_fx_phase
                CHECK (transaction_currency_code IS NULL)
        SQL);

        /*
         * The balance rule.
         *
         * A constraint trigger, deferred to COMMIT, so the whole entry is present when it is checked.
         *
         * `FOR EACH ROW` rather than per statement, and not by choice: PostgreSQL only accepts row-
         * level constraint triggers, and transition tables are unavailable to them. The consequence
         * is that a twenty-line entry runs the check twenty times at commit — each one an indexed
         * aggregate over that entry's lines, so the cost is small and bounded, and correctness is
         * what this rule is for.
         *
         * Draft entries are exempt: a half-entered entry on screen is legitimately unbalanced, and
         * posting is where the rule has to hold. `PostingService` also checks in PHP so the customer
         * gets a sentence rather than a constraint name; this is what holds when nothing checks in
         * PHP at all — a console command, a data fix, a future module.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_journal_entry_balanced() RETURNS trigger AS $$
            DECLARE
                entry_id UUID;
                entry_status TEXT;
                entry_number TEXT;
                total_debit NUMERIC(19, 4);
                total_credit NUMERIC(19, 4);
            BEGIN
                entry_id := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

                SELECT status, number INTO entry_status, entry_number
                FROM journal_entries
                WHERE id = entry_id;

                -- The entry may have been deleted in this same transaction (a discarded draft), in
                -- which case there is nothing left to balance.
                IF (entry_status IS NULL OR entry_status = 'draft') THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                INTO total_debit, total_credit
                FROM journal_lines
                WHERE journal_entry_id = entry_id;

                IF (total_debit <> total_credit) THEN
                    RAISE EXCEPTION
                        'Journal entry % does not balance: debits %, credits %.',
                        COALESCE(entry_number, entry_id::text),
                        total_debit,
                        total_credit
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_lines_balanced
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION asids_journal_entry_balanced()
        SQL);

        /*
         * Posted lines are immutable too.
         *
         * Without this, the entry header would be protected while its amounts could be edited freely
         * — which is the more valuable target. The entry's own trigger permits the reversal
         * transition; nothing here permits anything.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_journal_lines_immutable() RETURNS trigger AS $$
            DECLARE
                entry_status TEXT;
                entry_number TEXT;
            BEGIN
                SELECT status, number INTO entry_status, entry_number
                FROM journal_entries
                WHERE id = COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

                IF (entry_status IS NULL OR entry_status = 'draft') THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                RAISE EXCEPTION
                    'The lines of posted journal entry % cannot be changed. Post a reversing entry instead.',
                    COALESCE(entry_number, '(unnumbered)')
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_lines_immutable
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION asids_journal_lines_immutable()
        SQL);

        DB::statement("COMMENT ON TABLE journal_lines IS 'The ledger. Debits equal credits per entry, enforced by a deferred constraint trigger at commit.'");
        DB::statement("COMMENT ON COLUMN journal_lines.transaction_currency_code IS 'NULL means the company base currency at rate 1. Populated only from the FX phase onward.'");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_immutable ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balanced_on_update ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balanced_on_insert ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS asids_journal_lines_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS asids_journal_entry_balanced()');

        Schema::dropIfExists('journal_lines');
    }
};
