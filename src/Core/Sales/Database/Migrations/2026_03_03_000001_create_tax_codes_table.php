<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax codes, and the rates they carried at each point in time.
 *
 * Company-owned, per ADR 0002: a company owns its statutory registrations, and a tax code is
 * statutory configuration. Two companies in one workspace may be registered differently — one for VAT
 * and one not — so a shared table would be wrong even before the rates diverged.
 *
 * WHY `code` IS NOT UNIQUE PER COMPANY
 * -----------------------------------
 * A rate change is a new row, not an edit. `VAT` at 18% until June and 20% from July are two rows
 * sharing a code, and that is the whole point: an invoice issued in May must still resolve 18% years
 * later, so the old row cannot be overwritten. A plain unique index on `(company_id, upper(code))`
 * would make rate history impossible.
 *
 * What must never happen is two rows claiming the same code on the same date, because then no rate
 * resolves deterministically. The exclusion constraint below says exactly that, and it says it in the
 * database rather than in a service that a bulk import could bypass. It carries the uniqueness rule
 * and the overlap rule together — they are the same rule stated once.
 *
 * The mechanism is the one `fiscal_years` and `fiscal_periods` already use: a GiST exclusion
 * constraint over a `daterange`, backed by `btree_gist`, which the platform already provisions. No
 * second temporal mechanism is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name');

            $table->string('tax_type', 16);

            // A percentage, not a fraction: 18.0000 means 18%. That is what a tax authority publishes
            // and what an accountant reads on a screen, so storing 0.1800 would mean every human
            // reading the column had to convert in their head. The service divides by 100 when it
            // builds the factor for `Money::multipliedBy()`.
            //
            // numeric(9,4) rather than something tighter: four decimal places matches the ledger's own
            // scale, and rates with fractional percentages exist.
            $table->decimal('rate', 9, 4);

            // Where the tax posts. Restrict on delete, because a code pointing at a deleted account
            // would post nowhere. Nullable because an exempt or zero-rated code posts no tax at all.
            $table->foreignUuid('output_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            // Reserved for purchasing, which arrives in its own phase. Nullable and unused here rather
            // than absent, so the later migration adds behaviour rather than a column to a populated
            // table.
            $table->foreignUuid('input_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            // The effective range. `effective_from` is required — a rate with no start date cannot be
            // resolved against a document date. `effective_to` is nullable and means "still current",
            // which is the ordinary state of the newest row.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->text('notes')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Listing a company's codes. Leads with tenant_id per the platform convention, which also
            // matches the RLS predicate so the planner gets a selective first column.
            $table->index(['tenant_id', 'company_id', 'is_active']);
        });

        // The resolution path: company, then code, then the newest range that starts on or before the
        // date being asked about. A btree here rather than relying on the exclusion constraint's GiST
        // index, because resolution is an ordered lookup on a specific code and GiST is built for
        // overlap tests.
        DB::statement(<<<'SQL'
            CREATE INDEX tax_codes_resolution
                ON tax_codes (company_id, upper(code), effective_from DESC)
                WHERE deleted_at IS NULL
        SQL);

        $types = implode("', '", ['vat', 'svat', 'exempt', 'zero_rated']);
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_type_check CHECK (tax_type IN ('{$types}'))");

        // A percentage. The upper bound is not decoration: a rate entered as a fraction of 100 — 1800
        // for 18% — would otherwise multiply every invoice by eighteen.
        DB::statement('ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_rate_range_check CHECK (rate >= 0 AND rate <= 100)');

        // Exempt and zero-rated charge nothing by definition. Stated as a constraint because the two
        // are the classifications most often applied to a code that then carries a rate by mistake,
        // and the symptom is tax appearing on an invoice that should have none.
        DB::statement(<<<'SQL'
            ALTER TABLE tax_codes
                ADD CONSTRAINT tax_codes_zero_rate_types_check
                CHECK (tax_type NOT IN ('exempt', 'zero_rated') OR rate = 0)
        SQL);

        // A rate that charges something needs somewhere to post it. Cross-table validation — that the
        // account is a liability belonging to the same company — cannot live in a CHECK and is the
        // service's job; presence can, and does.
        DB::statement(<<<'SQL'
            ALTER TABLE tax_codes
                ADD CONSTRAINT tax_codes_output_account_required_check
                CHECK (rate = 0 OR output_account_id IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tax_codes
                ADD CONSTRAINT tax_codes_effective_range_check
                CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);

        /*
         * One rate per code per day, enforced by the database.
         *
         * `upper(code)` so that `vat` and `VAT` are one code, matching how every other code column in
         * the platform treats case. `daterange(..., '[]')` is inclusive at both ends, so a range ending
         * on the 30th and one starting on the 30th collide — which is correct, because a document dated
         * the 30th would otherwise have two candidate rates.
         *
         * A NULL `effective_to` produces an unbounded range, so an open-ended rate collides with any
         * later range for the same code. Ending the old row is therefore a required step when adding a
         * new rate, rather than something a caller can forget.
         *
         * Restricted to live rows: a soft-deleted code must not reserve its range for ever.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE tax_codes
                ADD CONSTRAINT tax_codes_no_overlapping_rates
                EXCLUDE USING gist (
                    company_id WITH =,
                    upper(code) WITH =,
                    daterange(effective_from, effective_to, '[]') WITH &&
                )
                WHERE (deleted_at IS NULL)
        SQL);

        DB::statement("COMMENT ON TABLE tax_codes IS 'Tax codes and the rate each carried over a date range. A rate change is a new row; history is never overwritten.'");
        DB::statement("COMMENT ON COLUMN tax_codes.rate IS 'A percentage. 18.0000 means 18%, not 1800%. Divided by 100 to build a Money multiplication factor.'");
        DB::statement("COMMENT ON COLUMN tax_codes.effective_to IS 'NULL means still current. The exclusion constraint treats it as unbounded, so an open range blocks any later one for the same code.'");
        DB::statement("COMMENT ON COLUMN tax_codes.input_account_id IS 'Reserved for purchasing, which arrives in a later phase. Unused by sales.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
