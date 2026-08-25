<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a cancelled receipt records about its own cancellation (ADR 0015 §A).
 *
 * The reversal sub-slice deferred by ADR 0014, now populating the boundary that migration prepared: a `status`
 * column written as a one-value `IN ('posted')`, and an immutability trigger whose frozen-column list already
 * excludes `status` and `updated_at`. This is the exact mirror of the invoice's
 * `2026_03_06_000001_add_cancellation_to_sales_invoices.php` on the receiving side.
 *
 * THREE THINGS TOGETHER, EACH DOING WHAT IT IS GOOD AT
 * ---------------------------------------------------
 *   - The widened status CHECK makes `'cancelled'` a reachable value, deliberately, where the shipped one-value
 *     CHECK anticipated it.
 *   - The tie-to-status CHECK ties all three metadata columns to the status: a cancelled receipt has a date and
 *     a reason, and nothing else carries either. This closes the gap the frozen-column list would leave — an
 *     otherwise-posted receipt quietly acquiring a `cancellation_reason`.
 *   - The trigger freezes the three columns on every update *except* the cancelling one, and a new finality
 *     guard refuses every update once `status = 'cancelled'`. So they are writable in exactly the posted →
 *     cancelled transition and immutable on both sides of it.
 *
 * `cancelled_by_id` is nullable even when cancelled, because a cancellation may be performed by the system
 * rather than a person — the same reasoning as `posted_by_id` and `created_by_id`, and the same `nullOnDelete`
 * behaviour, so a deleted user does not take the receipt's history with them.
 *
 * `receipt_allocations` is untouched: cancellation reads its rows to restore invoice balances and never writes
 * them, so its unconditional full-freeze stays exactly as shipped (§A, Gate-1 #7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('journal_entry_id');

            // Required by `PostingService::reverse()`, which takes the reason as a string rather than an
            // option — a reversal nobody explained is a reversal an auditor has to ask about. 500 to match the
            // invoice's.
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');

            $table->foreignUuid('cancelled_by_id')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
        });

        // Widen the status CHECK: `'cancelled'` becomes reachable, exactly as the shipped one-value CHECK
        // anticipated. Dropped and re-added, since a CHECK cannot be altered in place.
        DB::statement('ALTER TABLE customer_receipts DROP CONSTRAINT customer_receipts_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_status_check
                CHECK (status IN ('posted', 'cancelled'))
        SQL);

        // All three tied to the status, in one constraint rather than three, because the rule is about the
        // combination: a cancelled receipt has a date and a reason, and nothing else carries either.
        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_cancellation_matches_status_check
                CHECK (
                    CASE WHEN status = 'cancelled'
                        THEN cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
                        ELSE cancelled_at IS NULL
                            AND cancellation_reason IS NULL
                            AND cancelled_by_id IS NULL
                    END
                )
        SQL);

        // Replaced in full: `CREATE OR REPLACE FUNCTION` is the only way to change it, and the trigger itself
        // is left alone because it references the function by name. Everything but the two new guards is
        // unchanged from `2026_03_08_000004`.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_customer_receipts_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Receipt % has been posted and cannot be deleted. A posted receipt is a statutory record.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'cancelled') THEN
                    RAISE EXCEPTION 'Receipt % is cancelled and cannot be changed further. Its posting has already been reversed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.receipt_date IS DISTINCT FROM OLD.receipt_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Receipt % has been posted; it is immutable. Reverse it with a cancellation once that lands, never an edit.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Added by the cancellation sub-slice. Guarded on the transition rather than listed above,
                -- because the cancelling update is the one that must set these; every other update must not.
                -- After cancellation the `OLD.status = 'cancelled'` block at the top refuses the row entirely,
                -- so this covers the only window in which they could otherwise be written without the status
                -- following.
                IF (NEW.status <> 'cancelled' AND (
                    NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                    OR NEW.cancellation_reason IS DISTINCT FROM OLD.cancellation_reason
                    OR NEW.cancelled_by_id IS DISTINCT FROM OLD.cancelled_by_id
                )) THEN
                    RAISE EXCEPTION 'Receipt % is not being cancelled, so its cancellation details cannot be set.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement("COMMENT ON COLUMN customer_receipts.cancellation_reason IS 'Why the receipt was cancelled. Passed through to the reversing journal entry, so both records carry the same explanation.'");
    }

    public function down(): void
    {
        // The trigger function first, back to the `2026_03_08_000004` version — dropping the columns while the
        // function still references them would leave the trigger raising on its next fire.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_customer_receipts_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Receipt % has been posted and cannot be deleted. A posted receipt is a statutory record.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.receipt_date IS DISTINCT FROM OLD.receipt_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Receipt % has been posted; it is immutable. Reverse it with a cancellation once that lands, never an edit.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('ALTER TABLE customer_receipts DROP CONSTRAINT IF EXISTS customer_receipts_cancellation_matches_status_check');

        // Re-narrow the status CHECK to the shipped one-value form.
        DB::statement('ALTER TABLE customer_receipts DROP CONSTRAINT IF EXISTS customer_receipts_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_status_check
                CHECK (status IN ('posted'))
        SQL);

        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
