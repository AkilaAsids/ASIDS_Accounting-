<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Purchasing\Domain\Enums\BillStatus;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
final class BillFactory extends Factory
{
    protected $model = Bill::class;

    /**
     * A zero-total draft.
     *
     * Zero because the totals belong to the lines, and a factory that invented figures would produce a bill
     * whose header disagreed with its lines — which the money CHECKs would refuse or, worse, would not.
     * Callers wanting a costed bill go through `BillService`, which is the only thing that computes totals
     * correctly. `supplier_invoice_number` is set because it is NOT NULL from creation.
     *
     * `company_id` and `supplier_id` are not supplied and cannot be: a supplier belongs to a company, and a
     * conjured pair would be a supplier no bill could legitimately name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_invoice_number' => 'SUP-'.fake()->unique()->numerify('######'),
            'number' => null,
            'bill_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency_code' => 'LKR',
            'exchange_rate' => null,
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '0.0000',
            'amount_paid' => '0.0000',
            'amount_due' => '0.0000',
            // Set explicitly so an unsaved instance reads back the same as a saved one under strict mode.
            'status' => BillStatus::Draft,
            'posted_at' => null,
            'journal_entry_id' => null,
        ];
    }

    public function on(string $billDate, ?string $dueDate = null): self
    {
        return $this->state(fn (): array => [
            'bill_date' => $billDate,
            'due_date' => $dueDate ?? $billDate,
        ]);
    }

    /**
     * A posted bill carrying a number and a posted timestamp, so the status-tied CHECKs hold.
     */
    public function posted(): self
    {
        return $this->state(fn (): array => [
            'status' => BillStatus::Posted,
            'number' => 'BILL-'.fake()->unique()->numerify('####-##-####'),
            'posted_at' => now(),
        ]);
    }
}
