<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

use Asids\Core\Sales\Domain\Enums\TaxType;
use Carbon\CarbonImmutable;

/**
 * A tax code and its rate, as submitted for creation.
 *
 * CREATE ONLY, AND DELIBERATELY SO
 * --------------------------------
 * Updating a tax code goes through `TaxCodeService::update()` with an array of attributes rather than
 * this object, following `ChartOfAccountsService::update()`. That is not inconsistency — it is the one
 * mechanism in this codebase that can express the difference between "leave this field alone" and "set
 * this field to null".
 *
 * A readonly DTO cannot. Every optional field on it is nullable, so `effective_to: null` means both "not
 * supplied" and "clear the end date", and the service has to guess. That ambiguity is exactly the defect
 * recorded against `CustomerService::update()` as I3, and it matters more here: reopening a closed rate
 * range *requires* setting `effective_to` back to null, so a tax code that cannot express clearing is a
 * tax code whose range can never be reopened.
 *
 * `array_key_exists()` on a plain array answers it precisely — key absent means untouched, key present
 * with null means clear — which is why `update()` takes one. The trade is type safety at the boundary,
 * recovered by the service validating and casting every value it reads.
 *
 * Required: `code`, `name`, `taxType`, `rate`, `effectiveFrom`. Everything else is optional, and on
 * creation "optional" has no ambiguity to resolve: absent and null both mean the column starts empty.
 */
final readonly class TaxCodeData
{
    /**
     * @param  numeric-string  $rate  a percentage — 18.0000 means 18%, never 0.18
     */
    public function __construct(
        public string $code,
        public string $name,
        public TaxType $taxType,
        public string $rate,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo = null,
        public ?string $outputAccountId = null,
        public ?string $inputAccountId = null,
        public ?string $notes = null,
        public bool $isActive = true,
    ) {}

    /**
     * Built from a validated payload.
     *
     * The HTTP layer arrives in a later milestone; this exists now so the field mapping lives with the
     * DTO rather than being invented twice.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $rate */
        $rate = self::rateFrom($attributes);

        return new self(
            code: trim((string) $attributes['code']),
            name: trim((string) $attributes['name']),
            taxType: $attributes['tax_type'] instanceof TaxType
                ? $attributes['tax_type']
                : TaxType::from((string) $attributes['tax_type']),
            rate: $rate,
            effectiveFrom: CarbonImmutable::parse((string) $attributes['effective_from'])->startOfDay(),
            effectiveTo: isset($attributes['effective_to'])
                ? CarbonImmutable::parse((string) $attributes['effective_to'])->startOfDay()
                : null,
            outputAccountId: self::optionalString($attributes, 'output_account_id'),
            inputAccountId: self::optionalString($attributes, 'input_account_id'),
            notes: self::optionalString($attributes, 'notes'),
            isActive: (bool) ($attributes['is_active'] ?? true),
        );
    }

    /**
     * The rate as a string, whatever arrived.
     *
     * A JSON number reaches PHP as a float, and `(string) 18.1` is not always `'18.1'`. Normalising here
     * keeps the exactness the ledger depends on, and the service rejects anything non-numeric with a
     * message naming the field rather than letting the database do it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function rateFrom(array $attributes): string
    {
        $rate = $attributes['rate'] ?? '0';

        return is_float($rate) || is_int($rate)
            ? rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') ?: '0'
            : trim((string) $rate);
    }

    /**
     * An absent key and an empty string both mean "not given".
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function optionalString(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
