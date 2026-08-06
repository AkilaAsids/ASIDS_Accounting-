<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Colombo', 'Kandy', 'Galle', 'Jaffna', 'Negombo', 'Matara'])
                .' '.fake()->randomElement(['Branch', 'Outlet', 'Warehouse', 'Office']),
            'code' => Str::upper(Str::random(5)),
            // Never primary by default: exactly one primary per company is a partial unique
            // index, so a factory that defaulted to true would fail on the second branch.
            'is_primary' => false,
            'status' => OrganizationStatus::Active,
            'phone' => '+9411'.fake()->numerify('#######'),
            'city' => fake()->city(),
            'district' => 'Colombo',
        ];
    }

    public function primary(): self
    {
        return $this->state(fn (): array => [
            'is_primary' => true,
            // The check constraint forbids an archived primary branch.
            'status' => OrganizationStatus::Active,
            'archived_at' => null,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => [
            'is_primary' => false,
            'status' => OrganizationStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
