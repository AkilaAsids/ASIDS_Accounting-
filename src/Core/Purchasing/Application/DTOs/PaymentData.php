<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * A supplier payment, as submitted for recording (ADR 0020 §C3).
 *
 * RECORD ONLY. A payment is born posted and immutable (Gate-1 dec. 5, §A5), so — unlike `BillData`, which has
 * a companion update path — there is no update DTO; a payment is never edited.
 *
 * `amount` is supplied AND validated, not merely derived: the caller states the intended total and
 * `PaymentService::record()` refuses if Σ allocations ≠ it (AC-1.7). This catches a client that dropped an
 * allocation, and gives the deferred database backstop (§A5) a meaningful `amount` to check against.
 *
 * `bankAccountId` is caller-named (dec. 1), exactly as a bill line names its expense account — the account the
 * payment's credit lands in, validated Asset + postable + same-company in the posting map (§B).
 */
final readonly class PaymentData
{
    /**
     * @param  list<PaymentAllocationData>  $allocations
     * @param  numeric-string  $amount  the caller's stated total, validated == Σ allocations
     */
    public function __construct(
        public string $supplierId,
        public string $bankAccountId,
        public CarbonImmutable $paymentDate,
        public string $amount,
        public array $allocations,
        public ?string $reference = null,
        public ?string $notes = null,
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
        $amount = self::decimal($attributes, 'amount', '0');

        return new self(
            supplierId: (string) $attributes['supplier_id'],
            bankAccountId: (string) $attributes['bank_account_id'],
            paymentDate: CarbonImmutable::parse((string) $attributes['payment_date'])->startOfDay(),
            amount: $amount,
            allocations: array_map(
                static fn (array $allocation): PaymentAllocationData => PaymentAllocationData::fromArray($allocation),
                array_values($allocations),
            ),
            reference: self::optionalString($attributes, 'reference'),
            notes: self::optionalString($attributes, 'notes'),
            branchId: self::optionalString($attributes, 'branch_id'),
        );
    }

    /**
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
