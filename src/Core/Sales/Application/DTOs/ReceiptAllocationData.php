<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

/**
 * One line of a receipt's allocation, as submitted: an invoice and the amount to apply to it.
 *
 * `salesInvoiceId` is an id, not a number — an invoice has no date dimension to resolve against, unlike a tax
 * code. `amount` is a decimal string at the ledger's scale; never a float, so nothing about a payment's
 * arithmetic is approximated crossing the boundary.
 */
final readonly class ReceiptAllocationData
{
    /**
     * @param  numeric-string  $amount  the amount of the receipt applied to this invoice; must be positive
     */
    public function __construct(
        public string $salesInvoiceId,
        public string $amount,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $amount */
        $amount = trim((string) $attributes['amount']);

        return new self(
            salesInvoiceId: (string) $attributes['sales_invoice_id'],
            amount: $amount,
        );
    }
}
