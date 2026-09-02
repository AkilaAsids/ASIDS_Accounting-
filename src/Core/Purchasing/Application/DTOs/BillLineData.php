<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

/**
 * One bill line, as submitted — the payable-side mirror of `SalesInvoiceLineData`.
 *
 * `taxCode` is the **code**, not an id: which rate applies depends on the bill date, and `TaxRateResolver`
 * answers that from company + code + date. Accepting an id would let a caller pick a specific effective-dated
 * row and bypass the only mechanism that knows which row is correct for the document being written.
 *
 * `expenseAccountId` is an id, because an account has no date dimension: there is exactly one account with that
 * identifier and no resolution to perform.
 *
 * Discounts are one or the other. Both set is refused rather than silently preferred.
 */
final readonly class BillLineData
{
    /**
     * @param  numeric-string  $quantity  may be negative for a correction; never zero
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $discountPercent  a percentage — 10 means 10%
     * @param  numeric-string|null  $discountAmount  an absolute amount in the bill's currency
     */
    public function __construct(
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $expenseAccountId,
        public ?string $taxCode = null,
        public ?string $discountPercent = null,
        public ?string $discountAmount = null,
        public ?string $branchId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $quantity */
        $quantity = self::decimal($attributes, 'quantity', '1');
        /** @var numeric-string $unitPrice */
        $unitPrice = self::decimal($attributes, 'unit_price', '0');

        /** @var numeric-string|null $discountPercent */
        $discountPercent = array_key_exists('discount_percent', $attributes) && $attributes['discount_percent'] !== null
            ? self::decimal($attributes, 'discount_percent', '0')
            : null;

        /** @var numeric-string|null $discountAmount */
        $discountAmount = array_key_exists('discount_amount', $attributes) && $attributes['discount_amount'] !== null
            ? self::decimal($attributes, 'discount_amount', '0')
            : null;

        return new self(
            description: trim((string) $attributes['description']),
            quantity: $quantity,
            unitPrice: $unitPrice,
            expenseAccountId: (string) $attributes['expense_account_id'],
            taxCode: self::optionalString($attributes, 'tax_code'),
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            branchId: self::optionalString($attributes, 'branch_id'),
        );
    }

    /**
     * A decimal as a string, whatever arrived.
     *
     * A JSON number reaches PHP as a float, and `(string) 0.1` is not reliably `'0.1'`. Normalising through
     * `number_format` keeps the exactness the ledger depends on.
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

    /**
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
