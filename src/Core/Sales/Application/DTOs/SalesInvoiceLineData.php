<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

/**
 * One line, as submitted.
 *
 * `taxCode` is the **code**, not an id, and that is the substantive choice here. Which rate applies depends on
 * the invoice date, and `TaxRateResolver` answers that from company + code + date. Accepting an id instead
 * would let a caller pick a specific effective-dated row — including an expired or future one — and bypass the
 * only mechanism that knows which row is correct for the document being written.
 *
 * `revenueAccountId` is an id, because an account has no date dimension: there is exactly one account with
 * that identifier and no resolution to perform.
 *
 * Discounts are one or the other. Both set is refused rather than silently preferred, because a percentage a
 * salesperson negotiated and a fixed amount a manager approved are different claims and there is no correct
 * way to reconcile them.
 */
final readonly class SalesInvoiceLineData
{
    /**
     * @param  numeric-string  $quantity  may be negative for a correction; never zero
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $discountPercent  a percentage — 10 means 10%
     * @param  numeric-string|null  $discountAmount  an absolute amount in the invoice's currency
     */
    public function __construct(
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $revenueAccountId,
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
            revenueAccountId: (string) $attributes['revenue_account_id'],
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
     * `number_format` keeps the exactness the ledger depends on; the service rejects anything non-numeric with
     * a message naming the field.
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
