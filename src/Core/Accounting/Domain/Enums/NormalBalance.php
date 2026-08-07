<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * The side of the ledger that increases an account.
 *
 * Fixed by the account's type rather than chosen, which is why there is no setter for it anywhere.
 * An account whose normal balance disagreed with its type would report every figure with the wrong
 * sign on the balance sheet while remaining perfectly balanced — the hardest possible error to spot,
 * because the books still tie.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    /**
     * The signed value of a debit and credit pair, from this account's point of view.
     *
     * A debit-normal account with 100 debit and 30 credit has a balance of 70. A credit-normal
     * account with the same movements has a balance of -70 in debit terms, which is +70 in its own.
     * Every balance the platform reports is expressed this way, so an asset and a liability both
     * read as positive when they are in their expected state.
     */
    public function signedFrom(int $debitMinorUnits, int $creditMinorUnits): int
    {
        return match ($this) {
            self::Debit => $debitMinorUnits - $creditMinorUnits,
            self::Credit => $creditMinorUnits - $debitMinorUnits,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }
}
