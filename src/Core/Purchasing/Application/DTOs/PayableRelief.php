<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

use Asids\Core\Accounting\Application\DTOs\ForeignAmount;
use Asids\Core\Accounting\Domain\ValueObjects\Money;

/**
 * One settled bill's contribution to a foreign payment's Trade Payables relief (ADR 0022 §D2).
 *
 * A foreign payment relieves each settled bill's payable at THAT bill's booking rate, so each bill produces
 * its own `Dr Trade Payables` line: `baseRelieved` is the base-currency amount relieved (computed
 * incrementally so a fully-paid bill's payable zeroes exactly, §D3), and `overlay` is the transaction-currency
 * allocation at the bill's rate, recorded on the line.
 */
final readonly class PayableRelief
{
    public function __construct(
        public Money $baseRelieved,
        public ForeignAmount $overlay,
    ) {}
}
