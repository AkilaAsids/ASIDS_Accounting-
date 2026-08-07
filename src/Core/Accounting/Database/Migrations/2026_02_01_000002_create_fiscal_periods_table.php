<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The divisions of a fiscal year that postings are dated into — months, in every case this platform
 * supports today.
 *
 * A period is the unit of closing, and it is the unit `account_period_balances` aggregates over, so
 * two invariants matter more here than anywhere else in the module:
 *
 *   1. **No two periods of a company overlap.** A date inside two periods belongs to two closing
 *      states at once, and every report that groups by period double-counts it. Enforced by an
 *      exclusion constraint, not by the service.
 *   2. **A year's periods leave no gap.** A date inside no period cannot be posted at all — the
 *      entry has nowhere to go — and the customer experiences it as "the system refuses the 31st".
 *      Contiguity cannot be expressed as a single constraint, so it is enforced by the service that
 *      generates them and asserted by a test that walks each year end to end.
 *
 * Periods are generated for a whole year at once rather than on demand, so the gap is impossible by
 * construction rather than prevented at each insertion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('fiscal_year_id')
                ->constrained('fiscal_years')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // 1-based within the year. Period 1 of an April-start year is April, not January — the
            // number is the accountant's ordinal, not the calendar month.
            $table->unsignedSmallInteger('sequence');

            // "April 2026". Stored for the same reason the year's label is: derived in three places
            // means three subtly different strings on three different reports.
            $table->string('label', 32);

            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 16)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['fiscal_year_id', 'sequence']);
            $table->index(['tenant_id', 'company_id', 'starts_on']);

            // The lookup every posting performs: "which period does this date fall in, and is it
            // open?". Indexed on the range's start with the status alongside so the check is one
            // index scan rather than a scan plus a heap fetch.
            $table->index(['company_id', 'starts_on', 'ends_on', 'status'], 'fiscal_periods_posting_lookup');
        });

        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_dates_check CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_status_check CHECK (status IN ('open', 'closed', 'locked'))");

        // `closed_at` accompanies both non-open states. A locked period was closed first, so the
        // timestamp is meaningful for it too.
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_periods
                ADD CONSTRAINT fiscal_periods_closed_check
                CHECK ((status = 'open') = (closed_at IS NULL))
        SQL);

        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_sequence_check CHECK (sequence >= 1)');

        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_periods
                ADD CONSTRAINT fiscal_periods_no_overlap
                EXCLUDE USING gist (
                    company_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                )
        SQL);

        DB::statement("COMMENT ON TABLE fiscal_periods IS 'Divisions of a fiscal year that postings are dated into. The unit of closing and of balance aggregation.'");
        DB::statement("COMMENT ON COLUMN fiscal_periods.sequence IS 'Ordinal within the fiscal year, 1-based. Period 1 of an April-start year is April.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
