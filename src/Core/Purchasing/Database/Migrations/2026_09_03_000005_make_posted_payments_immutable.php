<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A posted payment is a historical fact (ADR 0020 §A5).
 *
 * The born-posted lifecycle (Gate-1 dec. 5) changes how immutability is expressed. A bill's triggers gate on
 * `OLD.status <> 'draft'` and on the parent being a draft, letting the draft→post UPDATE and the initial line
 * INSERTs through (`2026_09_02_000005_make_posted_bills_immutable.php:81-122`). A payment has no draft, so
 * those gates do not apply. The closest real precedent is `asids_journal_entries_immutable()`
 * (`2026_03_01_000001_add_source_document_to_journal_entries.php:82-125`): a journal entry is also born posted
 * and its trigger fires on every UPDATE/DELETE, permitting only the one legal exit and freezing every column
 * by name.
 *
 * THREE MECHANISMS
 * ----------------
 *   (1) `asids_supplier_payments_immutable()` — freezes a posted payment. No `WHEN` clause (every row is
 *       posted from birth); the record-and-post INSERT is untouched. In 8a nothing ever sets `cancelled`, so
 *       this makes a posted payment fully immutable this wave; the cancellation slice adds behaviour, not a
 *       migration (dec. 8). Columns listed by name, not `OLD IS DISTINCT FROM NEW`, so a column added later
 *       and forgotten is not silently mutable — the same reasoning as bills (`…000005…:29`).
 *   (2) `asids_payment_allocations_immutable()` — freezes existing allocation rows on UPDATE/DELETE. Unlike
 *       `asids_bill_lines_immutable()` it does NOT fire on INSERT and does NOT gate on parent status: a
 *       payment's allocations are inserted while the parent is already `posted`, so a parent-status gate would
 *       refuse the legitimate creation. INSERT is instead guarded by (3).
 *   (3) `asids_assert_payment_fully_allocated()` — a DEFERRED constraint trigger (the born-posted answer to
 *       `bill_lines`' insert gate). At commit it asserts SUM(allocations) = payment.amount, which does two
 *       jobs: it enforces full allocation (dec. 2) at the database, and it closes the hole the born-posted
 *       model opens — a rogue post-hoc INSERT of an extra allocation into a committed payment, which (2)
 *       cannot refuse because it does not fire on INSERT. The header `amount` is frozen by (1), so the
 *       offending transaction's commit finds Σ ≠ amount and raises. The legitimate record-and-post transaction
 *       inserts header and allocations together and passes at commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── (1) Payment freeze — mirror asids_journal_entries_immutable() ───────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_supplier_payments_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Payment % is a statutory record and cannot be deleted.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'cancelled') THEN
                    RAISE EXCEPTION 'Payment % has already been cancelled and cannot be changed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- The only transition a posted payment may make. Nothing in 8a sets it, so a posted payment is
                -- fully immutable this wave; the cancellation slice turns this into behaviour.
                IF (NEW.status <> 'cancelled') THEN
                    RAISE EXCEPTION 'A posted payment cannot be modified (payment %); cancel it instead.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.payment_date IS DISTINCT FROM OLD.payment_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Payment % has been posted; only its status may change (to cancelled).', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // No WHEN clause (unlike bills): every payment row is posted from birth, so every UPDATE/DELETE is a
        // post-hoc mutation to be checked. The record-and-post INSERT is untouched — the trigger is not
        // BEFORE INSERT.
        DB::statement(<<<'SQL'
            CREATE TRIGGER supplier_payments_immutable
                BEFORE UPDATE OR DELETE ON supplier_payments
                FOR EACH ROW
                EXECUTE FUNCTION asids_supplier_payments_immutable()
        SQL);

        // ── (2) Allocation freeze — UPDATE/DELETE only, no parent-status gate ───────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_payment_allocations_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The allocations of a posted payment cannot be changed.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // Fires on UPDATE OR DELETE only — NOT INSERT: a payment's allocations are inserted while the parent
        // is already posted, so an INSERT gate would refuse the legitimate creation. The rogue post-hoc
        // INSERT is caught by (3) at commit instead.
        DB::statement(<<<'SQL'
            CREATE TRIGGER payment_allocations_immutable
                BEFORE UPDATE OR DELETE ON payment_allocations
                FOR EACH ROW
                EXECUTE FUNCTION asids_payment_allocations_immutable()
        SQL);

        // ── (3) Deferred full-allocation constraint trigger ─────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_assert_payment_fully_allocated() RETURNS trigger AS $$
            DECLARE
                payment_id uuid;
                payment_amount numeric;
                allocated numeric;
            BEGIN
                -- The same function backs a trigger on both tables; the affected payment is found differently
                -- for each.
                IF (TG_TABLE_NAME = 'supplier_payments') THEN
                    payment_id := NEW.id;
                ELSE
                    payment_id := CASE WHEN TG_OP = 'DELETE'
                        THEN OLD.supplier_payment_id
                        ELSE NEW.supplier_payment_id
                    END;
                END IF;

                -- The payment may have gone in the same transaction (a cascade). Nothing to assert then.
                SELECT amount INTO payment_amount FROM supplier_payments WHERE id = payment_id;
                IF (payment_amount IS NULL) THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(amount), 0) INTO allocated
                FROM payment_allocations
                WHERE supplier_payment_id = payment_id;

                IF (allocated <> payment_amount) THEN
                    RAISE EXCEPTION 'Payment % allocations (%) must sum exactly to its amount (%).',
                        payment_id, allocated, payment_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // Deferred to commit: it is the database authority behind the service's readable Σ-check, and the
        // backstop against a rogue post-hoc allocation INSERT. The allocations-side trigger catches any
        // allocation change; the payments-side trigger catches a payment inserted with no allocations at all.
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER supplier_payments_fully_allocated
                AFTER INSERT OR UPDATE OR DELETE ON payment_allocations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION asids_assert_payment_fully_allocated()
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER supplier_payments_fully_allocated
                AFTER INSERT ON supplier_payments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION asids_assert_payment_fully_allocated()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS supplier_payments_fully_allocated ON supplier_payments');
        DB::statement('DROP TRIGGER IF EXISTS supplier_payments_fully_allocated ON payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS asids_assert_payment_fully_allocated()');

        DB::statement('DROP TRIGGER IF EXISTS payment_allocations_immutable ON payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS asids_payment_allocations_immutable()');

        DB::statement('DROP TRIGGER IF EXISTS supplier_payments_immutable ON supplier_payments');
        DB::statement('DROP FUNCTION IF EXISTS asids_supplier_payments_immutable()');
    }
};
