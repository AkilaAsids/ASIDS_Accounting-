<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless per-company document counters.
 *
 * WHY NOT A POSTGRESQL SEQUENCE
 * -----------------------------
 * A `SEQUENCE` is fast and concurrent precisely because it does not participate in the transaction:
 * a rolled-back transaction keeps the number it consumed. That produces gaps, and a gap in an
 * auditable document series is a question a customer has to answer to a tax authority — "what
 * happened to invoice 4017?" — with no evidence available either way.
 *
 * Sri Lankan e-invoicing will require gapless numbering outright. So the counter is a row, and it is
 * incremented under `SELECT … FOR UPDATE` inside the same transaction as the document. If the
 * document rolls back, so does the number.
 *
 * THE COST, STATED PLAINLY
 * ------------------------
 * Issuance serialises per company per document family per period. Two people posting a journal
 * voucher for the same company in the same month queue behind each other for the duration of one
 * transaction. For an SME posting tens of entries a day this is irrelevant. It would matter for a
 * high-volume tenant, and the mitigation then is a non-gapless sequence for internal document types
 * with the locked counter reserved for statutory ones — which is why `DocumentType` already answers
 * `requiresGaplessNumbering()` per case rather than assuming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('document_type', 32);

            // Numbering restarts per period rather than running forever: JV-2026-04-0001. An
            // accountant reading a number should be able to tell when it was issued, and a counter
            // that never resets reaches six digits and stops being readable.
            $table->string('period_key', 16);

            $table->unsignedBigInteger('next_number')->default(1);

            $table->timestampsTz();
        });

        // One counter per company, family and period. The unique index is what makes a race produce
        // a conflict rather than two counters that both hand out "1".
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX document_sequences_scope_unique
                ON document_sequences (company_id, document_type, period_key)
        SQL);

        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_next_number_check CHECK (next_number >= 1)');

        DB::statement("COMMENT ON TABLE document_sequences IS 'Gapless document counters. Incremented under row lock inside the document transaction, so a rollback returns the number.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
