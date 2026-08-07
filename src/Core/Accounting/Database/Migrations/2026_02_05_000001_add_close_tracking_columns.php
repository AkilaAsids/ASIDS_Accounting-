<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What closing needs to record beyond the fact of it.
 *
 * Tranche 1 built the calendar knowing a period would be closable; it did not yet know what closing
 * would need to *say*. Three additions, each because a question was going to be asked and there would
 * be no answer:
 *
 *   * `fiscal_periods.reopened_at` / `reopened_by_id` / `reopen_reason` — reopening a closed period
 *     changes figures that may already have been filed with a bank or a tax authority. "Who reopened
 *     March, when, and why" is the first thing an auditor asks, and a boolean flipping back to open
 *     answers none of it.
 *   * `fiscal_years.closing_entry_id` — the year-end entry is how a close is undone. Without the link,
 *     finding it means searching the journal by date and document type and hoping there is only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->timestampTz('reopened_at')->nullable()->after('closed_by_id');
            $table->foreignUuid('reopened_by_id')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->string('reopen_reason')->nullable()->after('reopened_by_id');
        });

        // A reason accompanies a reopening or neither exists. A period reopened with no recorded
        // reason is the case this column exists to prevent, so the database says so rather than
        // trusting every future caller to remember.
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_periods
                ADD CONSTRAINT fiscal_periods_reopen_reason_check
                CHECK ((reopened_at IS NULL) = (reopen_reason IS NULL))
        SQL);

        Schema::table('fiscal_years', function (Blueprint $table): void {
            // Restrict, not cascade: the closing entry is a posted journal entry and therefore
            // undeletable anyway, but stating it here means the link cannot be broken from this side
            // either.
            $table->foreignUuid('closing_entry_id')
                ->nullable()
                ->after('closed_by_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();
        });

        DB::statement("COMMENT ON COLUMN fiscal_periods.reopen_reason IS 'Required whenever a period is reopened. Reopening changes figures that may already have been filed.'");
        DB::statement("COMMENT ON COLUMN fiscal_years.closing_entry_id IS 'The year-end journal entry. Reversing it is the documented route to undoing a close.'");
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closing_entry_id');
        });

        DB::statement('ALTER TABLE fiscal_periods DROP CONSTRAINT IF EXISTS fiscal_periods_reopen_reason_check');

        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reopened_by_id');
            $table->dropColumn(['reopened_at', 'reopen_reason']);
        });
    }
};
