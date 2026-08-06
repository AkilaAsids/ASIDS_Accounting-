<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Tenancy\Domain\Enums\TenantStatus;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            // A random suffix, not just a slug of the name: the slug is a DNS label with a
            // unique index, and faker's company names collide often enough to make a test
            // suite flaky in a way that looks like a real bug.
            'slug' => Str::slug(Str::limit($name, 40, '')).'-'.Str::lower(Str::random(6)),
            'legal_name' => $name.' (Pvt) Ltd',
            'status' => TenantStatus::Active,
            'country_code' => 'LK',
            'currency_code' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'locale' => 'en',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+9411'.fake()->numerify('#######'),
            'provisioned_at' => now(),
        ];
    }

    public function provisioning(): self
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Provisioning,
            'provisioned_at' => null,
        ]);
    }

    public function suspended(string $reason = 'Non-payment'): self
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }

    public function onTrial(int $days = 14): self
    {
        return $this->state(fn (): array => [
            'trial_ends_at' => now()->addDays($days),
        ]);
    }
}
