<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a cancelled supplier payment records about its own cancellation (ADR 0023 §A).
 *
 * Cancelling reverses the payment's posting and restores the settled bills; the ledger already holds the
 * reversal's reason, date and author. These columns answer "what happened to this payment" without traversing
 * into Accounting — mirroring `sales_invoices` (`2026_03_06_000001_add_cancellation_to_sales_invoices.php`).
 *
 * NO `reversal_journal_entry_id` (ADR 0023 §D5 / ADR 0009 §B3): the reversal is already reachable —
 * `journal_entry_id` points at the original entry, which carries `reversed_by_entry_id`.
 *
 * WHY NO TRIGGER CHANGE — a deliberate divergence from the sales precedent
 * ----------------------------------------------------------------------
 * The sales invoice freeze permits issued→issued updates (payment figures move while the invoice stays issued),
 * so it needed an extra guard to stop those updates from also writing cancellation metadata. The supplier
 * payment freeze is stricter: `asids_supplier_payments_immutable()` already refuses EVERY update whose
 * `NEW.status <> 'cancelled'` (`2026_09_09_000004…:38-41`) and every update once `OLD.status = 'cancelled'`
 * (:33-36). So the only update that can touch these three columns is the one posted→cancelled transition — the
 * guarantee ADR 0023 §A3 asks for is already delivered by the existing trigger. Adding a conditional guard would
 * be dead code (unreachable after the status guard), so the protection here is the status-tied CHECK below plus
 * the untouched trigger. The columns are intentionally NOT added to the trigger's frozen-by-name list — that
 * list is the set that may never change on any permitted update, and these must change on the cancelling one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('journal_entry_id');

            // Required by `PostingService::reverse()`, which takes the reason as a string — a reversal nobody
            // explained is one an auditor has to chase (ADR 0023 §D6).
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');

            // Nullable even when cancelled: a cancellation may be system-driven, and a deleted user must not take
            // the payment's history with them (mirror `posted_by_id`/`created_by_id`; ADR 0023 §A2).
            $table->foreignUuid('cancelled_by_id')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
        });

        // All three tied to the status in one constraint: a cancelled payment has a date and a reason, and a
        // payment that is not cancelled carries none of the three. This is the DB authority behind the metadata's
        // conditional immutability (the trigger already confines writes to the cancelling transition).
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_cancellation_matches_status_check
                CHECK (
                    CASE WHEN status = 'cancelled'
                        THEN cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
                        ELSE cancelled_at IS NULL
                            AND cancellation_reason IS NULL
                            AND cancelled_by_id IS NULL
                    END
                )
        SQL);

        DB::statement("COMMENT ON CONSTRAINT supplier_payments_cancellation_matches_status_check ON supplier_payments IS 'Cancellation metadata exists exactly when the payment is cancelled. See ADR 0023.'");
        DB::statement("COMMENT ON COLUMN supplier_payments.cancellation_reason IS 'Why the payment was cancelled. Passed through to the reversing journal entry, so both records carry the same explanation.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT IF EXISTS supplier_payments_cancellation_matches_status_check');

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
