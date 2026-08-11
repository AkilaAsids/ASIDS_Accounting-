<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoice>
 */
final class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    /**
     * A zero-total draft.
     *
     * Zero because the totals belong to the lines, and a factory that invented figures would produce an
     * invoice whose header disagreed with its lines — which the money CHECKs would refuse or, worse, would not.
     * Callers wanting a costed invoice go through `SalesInvoiceService`, which is the only thing that computes
     * totals correctly.
     *
     * `company_id` and `customer_id` are not supplied and cannot be: a customer belongs to a company, and a
     * conjured pair would be a customer no invoice could legitimately name. Same contract as
     * `CustomerFactory` and `TaxCodeFactory`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => null,
            'reference' => 'PO-'.fake()->numerify('#####'),
            'invoice_date' => '2026-06-15',
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
            'status' => SalesInvoiceStatus::Draft,
            'issued_at' => null,
            'journal_entry_id' => null,
        ];
    }

    public function on(string $invoiceDate, ?string $dueDate = null): self
    {
        return $this->state(fn (): array => [
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate ?? $invoiceDate,
        ]);
    }
}
