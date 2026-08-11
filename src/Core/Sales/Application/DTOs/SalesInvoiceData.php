<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * A draft invoice, as submitted for creation.
 *
 * CREATE ONLY, for the same reason as `TaxCodeData`
 * ------------------------------------------------
 * `SalesInvoiceService::update()` takes an attributes array and uses `array_key_exists()`, following
 * `ChartOfAccountsService::update()`. A readonly DTO cannot distinguish "leave this alone" from "set this to
 * null", and on an invoice that distinction is load-bearing: clearing a header discount, a branch or a
 * reference all require expressing null deliberately, and a signature that could not would make them
 * permanent once set.
 *
 * `dueDate` is nullable and derived from the customer's payment terms when absent — the ordinary case, since
 * the terms are exactly what the customer record exists to hold.
 *
 * `number` is absent entirely. A draft never has one: gapless numbering is reserved inside the Milestone 5
 * issuing transaction so an abandoned draft consumes none, and a caller able to supply one could open a gap in
 * a series a tax authority audits for completeness.
 */
final readonly class SalesInvoiceData
{
    /**
     * @param  list<SalesInvoiceLineData>  $lines
     * @param  numeric-string|null  $discountAmount  a header discount, allocated across the lines in
     *                                               proportion to their subtotals
     */
    public function __construct(
        public string $customerId,
        public CarbonImmutable $invoiceDate,
        public array $lines,
        public ?CarbonImmutable $dueDate = null,
        public ?string $reference = null,
        public ?string $discountAmount = null,
        public ?string $branchId = null,
        public ?string $notes = null,
        public ?string $terms = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $attributes['lines'] ?? [];

        /** @var numeric-string|null $discount */
        $discount = array_key_exists('discount_amount', $attributes) && $attributes['discount_amount'] !== null
            ? trim((string) $attributes['discount_amount'])
            : null;

        return new self(
            customerId: (string) $attributes['customer_id'],
            invoiceDate: CarbonImmutable::parse((string) $attributes['invoice_date'])->startOfDay(),
            lines: array_map(
                static fn (array $line): SalesInvoiceLineData => SalesInvoiceLineData::fromArray($line),
                array_values($lines),
            ),
            dueDate: isset($attributes['due_date'])
                ? CarbonImmutable::parse((string) $attributes['due_date'])->startOfDay()
                : null,
            reference: self::optionalString($attributes, 'reference'),
            discountAmount: $discount,
            branchId: self::optionalString($attributes, 'branch_id'),
            notes: self::optionalString($attributes, 'notes'),
            terms: self::optionalString($attributes, 'terms'),
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
