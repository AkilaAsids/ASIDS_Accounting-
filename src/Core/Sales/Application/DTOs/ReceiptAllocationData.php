<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

/**
 * One line of a receipt's allocation, as submitted: an invoice and the amount to apply to it.
 *
 * `salesInvoiceId` is an id, not a number — an invoice has no date dimension to resolve against, unlike a tax
 * code. `amount` is a decimal string at the ledger's scale; never a float, so nothing about a payment's
 * arithmetic is approximated crossing the boundary.
 *
 * PER-ALLOCATION WITHHOLDING TAX (ADR 0017)
 * -----------------------------------------
 * Each allocation may carry its own withholding tax and certificate reference (Gate-1 #3): a customer may
 * withhold differently per invoice. `amount` stays the *gross* AR settled; `whtAmount` is the tax withheld
 * against it, so the *net* cash applied to the invoice is the derived `amount − whtAmount`, never stored. Both
 * new fields are optional, so every existing caller compiles and behaves identically: a null `whtAmount` is
 * "0" — no withholding — and the certificate reference is INDEPENDENT of the amount (Gate-2 fork (a)).
 */
final readonly class ReceiptAllocationData
{
    /**
     * @param  numeric-string  $amount  the gross AR settled on this invoice; must be positive
     * @param  numeric-string|null  $whtAmount  the tax withheld against this allocation; null ≡ "0" ≡ no WHT
     * @param  string|null  $whtCertificateReference  the customer's certificate/document reference, evidence only
     */
    public function __construct(
        public string $salesInvoiceId,
        public string $amount,
        public ?string $whtAmount = null,
        public ?string $whtCertificateReference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $amount */
        $amount = trim((string) $attributes['amount']);

        /** @var numeric-string|null $whtAmount */
        $whtAmount = self::optionalString($attributes, 'wht_amount');

        return new self(
            salesInvoiceId: (string) $attributes['sales_invoice_id'],
            amount: $amount,
            whtAmount: $whtAmount,
            whtCertificateReference: self::optionalString($attributes, 'wht_certificate_reference'),
        );
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
