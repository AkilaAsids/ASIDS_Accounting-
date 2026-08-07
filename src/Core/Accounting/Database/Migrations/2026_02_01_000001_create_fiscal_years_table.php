<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's financial years.
 *
 * Derived from `companies.fiscal_year_start_month/day` rather than configured separately, so a
 * company cannot end up with a chart of years that disagrees with its own stated fiscal calendar.
 * Sri Lanka's statutory assessment year begins in April, so a year here routinely spans two calendar
 * years — which is why the label is stored rather than computed from `starts_on`. "2026/27" is what
 * the customer calls it, and deriving that string in three different places produces three different
 * strings.
 *
 * Years are never deleted. A closed year is part of the statutory record, and the periods within it
 * carry the postings that a seven-year retention obligation applies to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // What the customer calls it: "2026/27" for an April start, "2026" for a calendar year.
            $table->string('label', 32);

            $table->date('starts_on');
            $table->date('ends_on');

            // Closing a year moves net income to retained earnings and locks its periods. Reversible,
            // because a year is sometimes closed before a late adjustment arrives.
            $table->boolean('is_closed')->default(false);
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['company_id', 'starts_on']);
            $table->index(['tenant_id', 'company_id', 'starts_on']);
        });

        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_dates_check CHECK (ends_on > starts_on)');

        // Both columns move together, as with every other closable thing in the platform. A year
        // marked closed with no timestamp cannot be reported on, and one with a timestamp but not
        // marked closed still accepts postings.
        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_closed_check CHECK (is_closed = (closed_at IS NOT NULL))');

        // No two of a company's years may overlap. Expressed as an exclusion constraint rather than
        // checked in the service, because a year that overlaps another puts a transaction date into
        // two years at once and every annual report then double-counts it.
        //
        // `daterange(starts_on, ends_on, '[]')` is inclusive at both ends, matching how the dates are
        // read everywhere else in this module.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years
                ADD CONSTRAINT fiscal_years_no_overlap
                EXCLUDE USING gist (
                    company_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                )
        SQL);

        DB::statement("COMMENT ON TABLE fiscal_years IS 'A company financial year, derived from its configured fiscal start. May span two calendar years.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
