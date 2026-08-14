<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a cancelled invoice records about its own cancellation.
 *
 * Stage 4 of Milestone 5. Cancelling reverses the invoice's posting, and the ledger already holds most of the
 * story: the reversal names its reason, its date and its author. But the ledger answers "what happened to this
 * entry", not "what happened to this invoice", and a user looking at a cancelled document should not have to
 * traverse into Accounting to find out when it was cancelled and by whom.
 *
 * NO `reversal_journal_entry_id`
 * -----------------------------
 * Approved decision B3, and it holds up: the reversal is already reachable. `journal_entry_id` points at the
 * original entry, and that entry carries `reversed_by_entry_id`. A column here would be a third copy of a fact
 * the ledger already owns twice, and the one most likely to drift.
 *
 * WHY THE CHECK RATHER THAN THE FROZEN COLUMN LIST
 * ------------------------------------------------
 * The obvious protection — adding these three to `asids_sales_invoices_immutable()`'s frozen list — would
 * refuse the one update that must set them. The trigger fires on any non-draft row, so the issued → cancelled
 * transition would be caught by its own metadata.
 *
 * So the protection is split, and each half does what it is good at:
 *
 *   - The CHECK ties all three to the status. Cancellation metadata cannot exist on an invoice that is not
 *     cancelled, and a cancelled invoice cannot lack the date and reason. This is what closes the gap the
 *     frozen list would otherwise leave — without it, an issued invoice could quietly acquire a
 *     `cancellation_reason` while remaining issued.
 *   - The trigger freezes them on every update *except* the cancelling one, and its existing
 *     `OLD.status = 'cancelled'` guard already refuses every update after that. So they are writable in
 *     exactly one transition and immutable on either side of it.
 *
 * `cancelled_by_id` is nullable even when cancelled, because a cancellation may be performed by the system
 * rather than a person — the same reasoning as `issued_by_id` and `created_by_id`, and the same
 * `nullOnDelete` behaviour, so a deleted user does not take the invoice's history with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('journal_entry_id');

            // Required by `PostingService::reverse()`, which takes the reason as a string rather than an
            // option — a reversal nobody explained is a reversal an auditor has to ask about.
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');

            $table->foreignUuid('cancelled_by_id')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
        });

        // All three tied to the status, in one constraint rather than three, because the rule is about the
        // combination: a cancelled invoice has a date and a reason, and nothing else carries either.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_cancellation_matches_status_check
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
        // is left alone because it references the function by name. Everything above the new block is
        // unchanged from `2026_03_05_000002`.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_sales_invoices_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Invoice % has been issued and cannot be deleted. Cancel it instead, which reverses its posting and leaves both entries in the ledger.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'cancelled') THEN
                    RAISE EXCEPTION 'Invoice % is cancelled and cannot be changed further. Its posting has already been reversed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.status = 'draft') THEN
                    RAISE EXCEPTION 'Invoice % cannot return to draft. It has consumed a number and posted to the ledger.', OLD.number
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
                    OR NEW.invoice_date IS DISTINCT FROM OLD.invoice_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.discount_total IS DISTINCT FROM OLD.discount_total
                    OR NEW.tax_total IS DISTINCT FROM OLD.tax_total
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.issued_by_id IS DISTINCT FROM OLD.issued_by_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.terms IS DISTINCT FROM OLD.terms
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Invoice % has been issued; only its status and payment figures may change. Cancel and reissue to correct anything else.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Added by Stage 4. Guarded on the transition rather than listed above, because the cancelling
                -- update is the one that must set these; every other update must not. After cancellation the
                -- `OLD.status = 'cancelled'` block at the top refuses the row entirely, so this covers the only
                -- window in which they could otherwise be written without the status following.
                IF (NEW.status <> 'cancelled' AND (
                    NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                    OR NEW.cancellation_reason IS DISTINCT FROM OLD.cancellation_reason
                    OR NEW.cancelled_by_id IS DISTINCT FROM OLD.cancelled_by_id
                )) THEN
                    RAISE EXCEPTION 'Invoice % is not being cancelled, so its cancellation details cannot be set.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement("COMMENT ON COLUMN sales_invoices.cancellation_reason IS 'Why the invoice was cancelled. Passed through to the reversing journal entry, so both records carry the same explanation.'");
    }

    public function down(): void
    {
        // The trigger function first, back to the Stage 1 version — dropping the columns while the function
        // still references them would leave the trigger raising on its next fire.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_sales_invoices_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Invoice % has been issued and cannot be deleted. Cancel it instead, which reverses its posting and leaves both entries in the ledger.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'cancelled') THEN
                    RAISE EXCEPTION 'Invoice % is cancelled and cannot be changed further. Its posting has already been reversed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.status = 'draft') THEN
                    RAISE EXCEPTION 'Invoice % cannot return to draft. It has consumed a number and posted to the ledger.', OLD.number
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
                    OR NEW.invoice_date IS DISTINCT FROM OLD.invoice_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.discount_total IS DISTINCT FROM OLD.discount_total
                    OR NEW.tax_total IS DISTINCT FROM OLD.tax_total
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.issued_by_id IS DISTINCT FROM OLD.issued_by_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.terms IS DISTINCT FROM OLD.terms
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Invoice % has been issued; only its status and payment figures may change. Cancel and reissue to correct anything else.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_cancellation_matches_status_check');

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
