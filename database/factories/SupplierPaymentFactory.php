<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Purchasing\Domain\Enums\PaymentStatus;
use Asids\Core\Purchasing\Domain\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
final class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    /**
     * A born-posted payment.
     *
     * Unlike `BillFactory` (which can default `journal_entry_id` to null because a draft has none), a payment
     * is born posted and `journal_entry_id` is NOT NULL and UNIQUE — nothing can invent a real journal entry,
     * so it is a required per-row override exactly like `company_id`/`supplier_id`/`bank_account_id`, never a
     * factory default. A payment with no allocations violates the deferred full-allocation trigger only *at
     * commit*, so a factory row survives inside a RefreshDatabase transaction; a test wanting a committed,
     * balanced payment goes through `PaymentService`.
     *
     * `company_id`, `supplier_id` and `bank_account_id` are not supplied and cannot be: a supplier and a
     * bank account belong to a company, and a conjured trio would be one no payment could legitimately name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'PAY-'.fake()->unique()->numerify('####-##-####'),
            'payment_date' => '2026-06-15',
            'currency_code' => 'LKR',
            'exchange_rate' => null,
            'amount' => '1000.0000',
            // Set explicitly so an unsaved instance reads back the same as a saved one under strict mode.
            'status' => PaymentStatus::Posted,
            'reference' => null,
            'notes' => null,
            'posted_at' => now(),
        ];
    }

    public function on(string $paymentDate): self
    {
        return $this->state(fn (): array => [
            'payment_date' => $paymentDate,
        ]);
    }
}
