<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

/**
 * A request to apply held credit to an invoice — ADR 0016 §D, §J.
 *
 * One call targets one invoice for one amount, drawn from the customer's held credit. By default the credit is
 * consumed FIFO across that customer's active records (oldest source receipt first, §E); naming a
 * `sourceReceiptId` overrides FIFO and consumes only that receipt's held credit.
 *
 * `amount` is a decimal string in the company's base currency, at its `currency_precision` (Gate-2 amendment).
 */
final readonly class ApplyCreditData
{
    /**
     * @param  numeric-string  $amount  the credit to apply; must be positive and at the company's currency precision
     */
    public function __construct(
        public string $salesInvoiceId,
        public string $amount,
        public ?string $sourceReceiptId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var numeric-string $amount */
        $amount = trim((string) $attributes['amount']);

        $source = $attributes['source_receipt_id'] ?? null;

        if ($source !== null) {
            $source = trim((string) $source);
            $source = $source === '' ? null : $source;
        }

        return new self(
            salesInvoiceId: (string) $attributes['sales_invoice_id'],
            amount: $amount,
            sourceReceiptId: $source,
        );
    }
}
