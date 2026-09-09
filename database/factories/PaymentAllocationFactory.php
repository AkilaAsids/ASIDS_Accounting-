<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Purchasing\Domain\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
final class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * A single positive allocation.
     *
     * `company_id`, `supplier_payment_id` and `bill_id` are required per-row overrides, not defaults: an
     * allocation names a payment and a bill that must already exist and share a company, and a conjured trio
     * would be one no allocation could legitimately name (mirror `BillLineFactory`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => '1000.0000',
        ];
    }
}
