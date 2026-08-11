<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoiceLine>
 */
final class SalesInvoiceLineFactory extends Factory
{
    protected $model = SalesInvoiceLine::class;

    /**
     * An untaxed line whose arithmetic is internally consistent.
     *
     * The `line_total = line_subtotal + tax_amount` CHECK applies to factory rows as much as to service ones,
     * so the defaults satisfy it rather than relying on a caller to notice. `sales_invoice_id`, `company_id`
     * and `revenue_account_id` cannot be invented and must be supplied.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_number' => 1,
            'description' => 'Consulting services',
            'quantity' => '1.0000',
            'unit_price' => '1000.0000',
            'discount_percent' => null,
            'discount_amount' => null,
            'line_subtotal' => '1000.0000',
            'tax_code_id' => null,
            'tax_rate' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '1000.0000',
            'branch_id' => null,
        ];
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice
     */
    public function costing(string $quantity, string $unitPrice): self
    {
        $subtotal = bcmul($quantity, $unitPrice, 4);

        return $this->state(fn (): array => [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_subtotal' => $subtotal,
            'line_total' => $subtotal,
        ]);
    }

    public function atPosition(int $position): self
    {
        return $this->state(fn (): array => ['line_number' => $position]);
    }
}
