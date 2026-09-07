<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payment headers — the payable-side settlement document (ADR 0020 §A2).
 *
 * Mirrors the `bills` header (`2026_09_02_000002_create_bills_table.php:38-106`), with the draft machinery
 * removed because a payment is *born posted* (Gate-1 dec. 5): a payment records cash that has already moved,
 * so it is recorded and posted in one step. That inverts the bill's lifecycle at the database —
 *
 *   - `number` is NOT NULL (unlike `bills.number`, `:62`): there is no draft, so every persisted payment is
 *     posted and numbered. The unique index is full, not partial (§A4).
 *   - `journal_entry_id` is NOT NULL (unlike `bills.journal_entry_id`, `:95`): a payment has a ledger entry
 *     from birth. Still UNIQUE — the database guard against a payment posting twice.
 *   - `posted_at` is NOT NULL for the same reason.
 *   - no `posted_at`/`number`/status draft-boundary CHECKs — there is no draft state to be half in.
 *
 * NO `deleted_at`, NO CANCELLATION COLUMNS, DELIBERATELY
 * -----------------------------------------------------
 * A payment is a statutory record; hard-deletion is refused by the freeze trigger (§A5), so no soft-delete is
 * needed. Cancellation columns arrive with the cancellation slice exactly as bills deferred theirs — this
 * wave only reserves `cancelled` in the status CHECK (Gate-1 dec. 8).
 *
 * The bank/cash account is caller-named (Gate-1 dec. 1), exactly as a bill line names its expense account:
 * `1110`/`1120` are keyless in the chart template, so a payment names `bank_account_id` explicitly and the
 * posting map validates it Asset + postable + same-company (§B). No new system account, no backfill (§A0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The reporting dimension, as on `bills:50`; passed to the journal lines.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Restrict, not cascade: a payment names its supplier and that name has to stay resolvable —
            // mirror `bills.supplier_id` (`bills:54`).
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // The caller-named account the credit lands in (dec. 1); must stay resolvable — mirror
            // `bill_lines.expense_account_id` (`bill_lines:67`). Validated Asset + postable + same company in
            // the posting map (§B), because the database cannot join to `accounts` in a CHECK.
            $table->foreignUuid('bank_account_id')->constrained('accounts')->restrictOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // The internal PAY- number, drawn inside the record-and-post transaction. NOT NULL (unlike
            // `bills.number`, `:62`) because there is no draft — every persisted payment is posted and
            // numbered. Gapless: a payment is originated by us, not received (ADR 0020 §D).
            $table->string('number', 40);

            // ── Dates ───────────────────────────────────────────────────────
            // The value date. Drives the fiscal period and the JV entry_date. Mirror `bills.bill_date` (`:66`).
            $table->date('payment_date');

            // ── Currency ────────────────────────────────────────────────────
            $table->char('currency_code', 3);
            // Held at NULL by the phase-scoped CHECK below, which the FX phase drops — mirror
            // `bills.exchange_rate` (`:73`).
            $table->decimal('exchange_rate', 19, 10)->nullable();

            // ── Money ───────────────────────────────────────────────────────
            // The payment total = Σ allocations (dec. 2). Both the Dr Trade Payables and the Cr Bank are this
            // figure. The deferred full-allocation trigger (§A5) asserts Σ allocations = amount at commit.
            $table->decimal('amount', 19, 4);

            // ── Lifecycle ───────────────────────────────────────────────────
            // `cancelled` reserved (dec. 8); no `draft` (dec. 5). Born posted.
            $table->string('status', 16)->default('posted');

            // Optional external reference (bank txn id, cheque no). Not unique — a payment has no mandatory
            // external key, and dec. 7 leaves duplicate detection to audit.
            $table->string('reference', 120)->nullable();

            // Free text; the only fillable field (mirror `Bill::$fillable`, `Bill.php:94`).
            $table->text('notes')->nullable();

            // The ledger entry this payment posted. NOT NULL (born posted) and UNIQUE — the database guard
            // against a payment posting twice, the guarantee that holds under concurrency.
            $table->foreignUuid('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();

            $table->timestampTz('posted_at');
            $table->foreignUuid('posted_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'payment_date']);
        });

        // Full unique (not partial): number is always set, so unlike bills' partial index on a nullable draft
        // number (`bills:110-114`) this needs no WHERE clause.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX supplier_payments_company_number_unique
                ON supplier_payments (company_id, number)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_status_check
                CHECK (status IN ('posted', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_amount_positive_check
                CHECK (amount > 0)
        SQL);

        // Held at NULL until the FX phase drops it — mirror `bills_single_currency_until_fx_phase`
        // (`bills:182-186`).
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_single_currency_until_fx_phase
                CHECK (exchange_rate IS NULL)
        SQL);

        DB::statement("COMMENT ON TABLE supplier_payments IS 'Supplier payment headers. Born posted (no draft); a statutory record that cannot be deleted. See ADR 0020.'");
        DB::statement("COMMENT ON COLUMN supplier_payments.number IS 'The internal PAY- number, drawn inside the record-and-post transaction. NOT NULL and gapless: a payment is originated by us, not received.'");
        DB::statement("COMMENT ON COLUMN supplier_payments.journal_entry_id IS 'NOT NULL and UNIQUE. The database-level guard against a payment posting twice.'");
        DB::statement("COMMENT ON CONSTRAINT supplier_payments_single_currency_until_fx_phase ON supplier_payments IS 'Dropped by the FX phase.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
