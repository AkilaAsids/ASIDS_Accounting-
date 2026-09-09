<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the WHT snapshot columns on a posted payment (ADR 0021 §A4; the column-drift risk).
 *
 * The Wave 8a payment freeze function (`asids_supplier_payments_immutable()`,
 * `2026_09_03_000005_make_posted_payments_immutable.php`) lists every frozen column BY NAME rather than
 * `OLD IS DISTINCT FROM NEW`, precisely so a column added later and forgotten does not become silently mutable
 * on a posted payment. `wht_amount` and `wht_rate` are exactly that case — a repointable withheld amount would
 * let a posted payment's split be rewritten after the fact. This `CREATE OR REPLACE` adds both to the frozen
 * list; the trigger itself is unchanged (it references the function by name).
 *
 * Replaced in full rather than patched, because `CREATE OR REPLACE FUNCTION` is the only way to change it.
 * `down()` restores the Wave 8a text (without the two columns).
 */
return new class extends Migration
{
    public function up(): void
    {
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
                    OR NEW.wht_amount IS DISTINCT FROM OLD.wht_amount
                    OR NEW.wht_rate IS DISTINCT FROM OLD.wht_rate
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
    }

    public function down(): void
    {
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
    }
};
