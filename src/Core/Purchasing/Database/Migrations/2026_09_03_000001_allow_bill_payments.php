<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Release the payments seam on `bills` — the migration Wave 7 was built to receive (ADR 0020 §A4).
 *
 * Wave 7 shipped `bills.amount_paid`/`amount_due` held at zero by `bills_no_payments_until_payments_phase`
 * (`2026_09_02_000002_create_bills_table.php:188-192`), naming *this* phase as the dropper in its own comment
 * (`bills:199`). Supplier payments now maintain those columns, so the phase CHECK comes off.
 *
 * In its place goes a stronger invariant realising Gate-1 dec. 3 at the database: `bills_non_negative_check`
 * (`bills:172-176`) asserts `amount_paid >= 0` but *not* `amount_due >= 0`, so with `amount_paid` free to move
 * nothing today stops an overpayment driving `amount_due` negative. Given `amount_due = total - amount_paid`
 * (`bills_amount_due_check`, `bills:166-170`), `amount_due >= 0` is equivalent to `amount_paid <= total` — the
 * payable-side backstop to the per-bill allocation cap. The service refuses an over-cap allocation readably
 * under a row lock (§C4, AC-1.6); this CHECK is the authority that holds under concurrency and raw access, the
 * same "service message + database authority" split bills already use for the duplicate-number guard.
 *
 * The drop must not disturb `bills_amount_due_check` or `bills_non_negative_check` (Gate-1 Risk 4); both are
 * retained, and the schema-mutation test asserts all three outcomes after this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase');

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_amount_due_non_negative_check
                CHECK (amount_due >= 0)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT bills_amount_due_non_negative_check ON bills IS 'The payable-side backstop to the per-bill allocation cap: amount_due >= 0, i.e. amount_paid <= total. See ADR 0020.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_amount_due_non_negative_check');

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_no_payments_until_payments_phase
                CHECK (amount_paid = 0)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT bills_no_payments_until_payments_phase ON bills IS 'Dropped by the payments phase (Wave 8).'");
    }
};
