<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Sales\Domain\Enums\CustomerStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' (Pvt) Ltd',
            // Random rather than sequential: a factory that generated `C-0001` would collide with the
            // codes `CustomerService` derives, and the collision would only appear in tests that
            // happen to mix the two.
            'code' => 'C-'.Str::upper(Str::random(6)),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+9411'.fake()->numerify('#######'),
            'address_line_1' => fake()->streetAddress(),
            'city' => 'Colombo',
            'district' => 'Colombo',
            'postal_code' => fake()->numerify('#####'),
            'country_code' => 'LK',
            'payment_terms_days' => 30,
            'credit_limit' => null,
            'is_vat_registered' => false,
            // Set explicitly rather than relying on the column default, so an unsaved instance reads
            // back the same as a saved one under `Model::shouldBeStrict()`.
            'status' => CustomerStatus::Active,
            'archived_at' => null,
        ];
    }

    public function vatRegistered(): self
    {
        return $this->state(fn (): array => [
            'is_vat_registered' => true,
            'vat_registration_number' => fake()->numerify('#########-7000'),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Inactive]);
    }

    /**
     * The status and the timestamp move together, because the CHECK constraint requires it.
     */
    public function archived(): self
    {
        return $this->state(fn (): array => [
            'status' => CustomerStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    /**
     * @param  numeric-string  $limit
     */
    public function withCreditLimit(string $limit): self
    {
        return $this->state(fn (): array => ['credit_limit' => $limit]);
    }

    public function onTerms(int $days): self
    {
        return $this->state(fn (): array => ['payment_terms_days' => $days]);
    }
}
