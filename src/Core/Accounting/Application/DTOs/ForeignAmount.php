<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\DTOs;

use Asids\Core\Accounting\Domain\ValueObjects\Money;

/**
 * The transaction-currency face of a ledger line — the FX overlay (ADR 0022 §B2).
 *
 * A journal line's `debit`/`credit` are always the **base**-currency amount and the side; when the line
 * originated in a foreign currency, this bundles the transaction-currency amount and the rate that produced
 * the base amount (`base = round(amount × rate)`, `Money::convertedTo`). Bundling the two in one value object
 * makes the all-or-none shape a type invariant — a line either carries the whole overlay or none of it,
 * mirroring `journal_lines_currency_columns_check`.
 *
 * The transaction currency lives on the `Money`; `rate` is transaction → base, a positive numeric string
 * (persisted to `journal_lines.exchange_rate`, `decimal(19,10)`).
 */
final readonly class ForeignAmount
{
    public function __construct(
        public Money $amount,
        public string $rate,
    ) {}
}
