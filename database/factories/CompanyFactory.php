<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' (Pvt) Ltd',
            'code' => Str::upper(Str::random(6)),
            'slug' => Str::slug(Str::limit($name, 60, '')).'-'.Str::lower(Str::random(5)),
            'base_currency_code' => 'LKR',
            'currency_precision' => 2,
            // April, matching Sri Lanka's statutory assessment year — so tests exercise a
            // non-calendar fiscal year by default rather than the easy case.
            'fiscal_year_start_month' => 4,
            'fiscal_year_start_day' => 1,
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
            'locale' => 'en',
            'status' => OrganizationStatus::Active,
            'is_default' => false,
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+9411'.fake()->numerify('#######'),
            'city' => 'Colombo',
            'district' => 'Colombo',
            'postal_code' => fake()->numerify('#####'),
        ];
    }

    public function vatRegistered(): self
    {
        return $this->state(fn (): array => [
            'is_vat_registered' => true,
            'vat_registration_number' => fake()->numerify('#########').'-7000',
            'tax_identification_number' => fake()->numerify('#########'),
        ]);
    }

    /**
     * SVAT presupposes VAT — enforced by a database check constraint — so this state sets
     * both rather than leaving a combination the insert would reject.
     */
    public function svatRegistered(): self
    {
        return $this->vatRegistered()->state(fn (): array => [
            'is_svat_registered' => true,
            'svat_registration_number' => 'SVAT'.fake()->numerify('######'),
        ]);
    }

    public function calendarFiscalYear(): self
    {
        return $this->state(fn (): array => [
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::Archived,
            'archived_at' => now(),
            'is_default' => false,
        ]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
