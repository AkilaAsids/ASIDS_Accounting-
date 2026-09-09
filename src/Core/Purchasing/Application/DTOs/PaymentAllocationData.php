<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

/**
 * One allocation of a payment to a bill, as submitted (ADR 0020 §C3).
 *
 * `billId` is an id — an account has no date dimension and there is exactly one bill with that identifier, so
 * nothing to resolve (mirror `BillLineData`'s `expenseAccountId`). `amount` is a numeric-string: how much of
 * the payment settles this bill, validated by the service to be positive and no greater than the bill's
 * current `amount_due` (dec. 3).
 */
final readonly class PaymentAllocationData
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public string $billId,
        public string $amount,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $amount */
        $amount = self::decimal($attributes, 'amount', '0');

        return new self(
            billId: (string) $attributes['bill_id'],
            amount: $amount,
        );
    }

    /**
     * A decimal as a string, whatever arrived — a JSON number reaches PHP as a float, and `(string) 0.1` is
     * not reliably `'0.1'`. Mirror `BillLineData::decimal`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function decimal(array $attributes, string $key, string $default): string
    {
        $value = $attributes[$key] ?? $default;

        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? $default : $trimmed;
    }
}
