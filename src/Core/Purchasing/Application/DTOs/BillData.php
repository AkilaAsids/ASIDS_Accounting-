<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * A draft bill, as submitted for creation — the payable-side mirror of `SalesInvoiceData`.
 *
 * CREATE ONLY. `BillService::updateDraft()` takes an attributes array and uses `array_key_exists()`, because a
 * readonly DTO cannot distinguish "leave this alone" from "set this to null", and on a bill that distinction is
 * load-bearing: clearing a header discount, a branch, notes or terms all require expressing null deliberately.
 *
 * `supplierInvoiceNumber` is required — the one non-nullable string beyond the line list. A supplier assigns
 * its own number and we do not, and it is the statutory identity and the duplicate-guard key, so a bill cannot
 * be drafted without it.
 *
 * `dueDate` is nullable and derived from the supplier's payment terms when absent.
 *
 * `number` is absent entirely. A caller may never supply the internal number: it is reserved inside the
 * posting transaction, and a bill in draft is already identified by its supplier invoice number.
 */
final readonly class BillData
{
    /**
     * @param  list<BillLineData>  $lines
     * @param  numeric-string|null  $discountAmount  a header discount, allocated across the lines in
     *                                               proportion to their subtotals
     */
    public function __construct(
        public string $supplierId,
        public CarbonImmutable $billDate,
        public string $supplierInvoiceNumber,
        public array $lines,
        public ?CarbonImmutable $dueDate = null,
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
            supplierId: (string) $attributes['supplier_id'],
            billDate: CarbonImmutable::parse((string) $attributes['bill_date'])->startOfDay(),
            supplierInvoiceNumber: trim((string) $attributes['supplier_invoice_number']),
            lines: array_map(
                static fn (array $line): BillLineData => BillLineData::fromArray($line),
                array_values($lines),
            ),
            dueDate: isset($attributes['due_date'])
                ? CarbonImmutable::parse((string) $attributes['due_date'])->startOfDay()
                : null,
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
