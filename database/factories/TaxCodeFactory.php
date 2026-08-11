<?php

declare(strict_types=1);

namespace Database\Factories;

use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaxCode>
 */
final class TaxCodeFactory extends Factory
{
    protected $model = TaxCode::class;

    /**
     * A zero-rated code by default.
     *
     * Zero because a charging rate needs an output account, and a factory cannot invent one that belongs
     * to the right company — the database would refuse the row. A caller wanting a charging rate supplies
     * the account through `charging()`, which is the only way to get one that is actually valid.
     *
     * No seeded VAT rate anywhere in this file, per the decision not to assert a Sri Lankan rate as a
     * product assumption. Test fixtures state their own rates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Random rather than a fixed 'VAT': the exclusion constraint keys on the code, so two default
            // instances for one company would collide on their ranges and the failure would look like a
            // bug in whatever test happened to create both.
            'code' => 'TAX-'.Str::upper(Str::random(6)),
            'name' => 'Zero rated supply',
            'tax_type' => TaxType::ZeroRated,
            'rate' => '0.0000',
            'output_account_id' => null,
            'input_account_id' => null,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ];
    }

    /**
     * A code that actually charges, posting to the given liability account.
     *
     * @param  numeric-string  $rate  a percentage — 18 means 18%
     */
    public function charging(string $rate, string $outputAccountId): self
    {
        return $this->state(fn (): array => [
            'tax_type' => TaxType::Vat,
            'name' => 'Value Added Tax',
            'rate' => $rate,
            'output_account_id' => $outputAccountId,
        ]);
    }

    public function svat(): self
    {
        return $this->state(fn (): array => [
            'tax_type' => TaxType::Svat,
            'name' => 'Suspended VAT',
            'rate' => '0.0000',
        ]);
    }

    public function exempt(): self
    {
        return $this->state(fn (): array => [
            'tax_type' => TaxType::Exempt,
            'name' => 'Exempt supply',
            'rate' => '0.0000',
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A closed range, for testing resolution against historical rates.
     */
    public function effective(string $from, ?string $to = null): self
    {
        return $this->state(fn (): array => [
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }
}
