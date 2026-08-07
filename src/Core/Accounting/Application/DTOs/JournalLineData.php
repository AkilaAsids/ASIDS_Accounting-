<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\DTOs;

use Asids\Core\Accounting\Domain\ValueObjects\Money;

/**
 * One side of an entry, as submitted.
 *
 * Debit and credit are separate and both optional, mirroring the storage — a caller states which
 * side the amount is on rather than passing a sign the service has to interpret. Exactly one must be
 * present, which `JournalEntryData` checks so the failure names the line.
 */
final readonly class JournalLineData
{
    public function __construct(
        public string $accountId,
        public ?Money $debit = null,
        public ?Money $credit = null,
        public ?string $branchId = null,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes, string $currency): self
    {
        // `isset()` already excludes null, so a further null comparison would be dead code. The
        // empty-string check is the one that matters: an untouched amount field posts as "".
        $debit = isset($attributes['debit']) && $attributes['debit'] !== ''
            ? Money::of((string) $attributes['debit'], $currency)
            : null;

        $credit = isset($attributes['credit']) && $attributes['credit'] !== ''
            ? Money::of((string) $attributes['credit'], $currency)
            : null;

        return new self(
            accountId: (string) $attributes['account_id'],
            debit: $debit,
            credit: $credit,
            branchId: isset($attributes['branch_id']) ? (string) $attributes['branch_id'] : null,
            description: isset($attributes['description']) ? (string) $attributes['description'] : null,
        );
    }

    /**
     * The amount, whichever side it is on. Never negative — the side carries the direction.
     */
    public function amount(string $currency): Money
    {
        return $this->debit ?? $this->credit ?? Money::zero($currency);
    }

    public function isDebit(): bool
    {
        return $this->debit !== null;
    }

    /**
     * Whether exactly one side carries a non-zero amount.
     *
     * A line with both is ambiguous; a line with neither is noise that passes every balance check
     * because zero equals zero, and then appears on the account ledger as a movement of nothing.
     */
    public function isOneSided(): bool
    {
        $hasDebit = $this->debit !== null && ! $this->debit->isZero();
        $hasCredit = $this->credit !== null && ! $this->credit->isZero();

        return $hasDebit !== $hasCredit;
    }

    public function isNegative(): bool
    {
        return ($this->debit?->isNegative() ?? false) || ($this->credit?->isNegative() ?? false);
    }
}
