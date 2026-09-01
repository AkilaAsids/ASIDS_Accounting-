<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' (Pvt) Ltd',
            // Random rather than sequential: a factory that generated `S-0001` would collide with the
            // codes `SupplierService` derives, and the collision would only appear in tests that
            // happen to mix the two.
            'code' => 'S-'.Str::upper(Str::random(6)),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+9411'.fake()->numerify('#######'),
            'address_line_1' => fake()->streetAddress(),
            'city' => 'Colombo',
            'district' => 'Colombo',
            'postal_code' => fake()->numerify('#####'),
            'country_code' => 'LK',
            'payment_terms_days' => 30,
            'is_vat_registered' => false,
            // Set explicitly rather than relying on the column default, so an unsaved instance reads
            // back the same as a saved one under `Model::shouldBeStrict()`.
            'status' => SupplierStatus::Active,
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
        return $this->state(fn (): array => ['status' => SupplierStatus::Inactive]);
    }

    /**
     * The status and the timestamp move together, because the CHECK constraint requires it.
     */
    public function archived(): self
    {
        return $this->state(fn (): array => [
            'status' => SupplierStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function onTerms(int $days): self
    {
        return $this->state(fn (): array => ['payment_terms_days' => $days]);
    }
}
