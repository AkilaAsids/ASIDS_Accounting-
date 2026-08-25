<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\DTOs;

use Asids\Core\Sales\Domain\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * A customer receipt, as submitted for recording.
 *
 * RECORD-AND-ALLOCATE IS ATOMIC
 * -----------------------------
 * The allocations are part of the DTO rather than a later step, because Gate-1 #2 made recording and full
 * allocation one operation: a receipt is either fully allocated (Σ allocations = amount) or refused. There is
 * no interim "recorded but unallocated" state, which would be unallocated credit-on-account — a deferred
 * feature this wave must not half-build. So there is no `record()` that leaves a receipt to be allocated
 * afterwards; the amount and the lines arrive together.
 *
 * `amount` is a decimal string, and `bankAccountId` names an existing GL asset account the receipt debits
 * (Gate-1 #3) — not a bank-account entity, which is the deferred Banking phase.
 */
final readonly class ReceiptData
{
    /**
     * @param  numeric-string  $amount  the money received; must be positive and in the company's base currency
     * @param  list<ReceiptAllocationData>  $allocations  must sum exactly to `amount`
     */
    public function __construct(
        public string $customerId,
        public CarbonImmutable $receiptDate,
        public string $amount,
        public PaymentMethod $paymentMethod,
        public string $bankAccountId,
        public array $allocations,
        public ?string $reference = null,
        public ?string $branchId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        /** @var list<array<string, mixed>> $allocations */
        $allocations = $attributes['allocations'] ?? [];

        /** @var numeric-string $amount */
        $amount = trim((string) $attributes['amount']);

        return new self(
            customerId: (string) $attributes['customer_id'],
            receiptDate: CarbonImmutable::parse((string) $attributes['receipt_date'])->startOfDay(),
            amount: $amount,
            paymentMethod: $attributes['payment_method'] instanceof PaymentMethod
                ? $attributes['payment_method']
                : PaymentMethod::from((string) $attributes['payment_method']),
            bankAccountId: (string) $attributes['bank_account_id'],
            allocations: array_map(
                static fn (array $line): ReceiptAllocationData => ReceiptAllocationData::fromArray($line),
                array_values($allocations),
            ),
            reference: self::optionalString($attributes, 'reference'),
            branchId: self::optionalString($attributes, 'branch_id'),
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
