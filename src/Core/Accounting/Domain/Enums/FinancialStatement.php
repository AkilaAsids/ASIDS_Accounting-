<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * Which statement an account's balance is reported on.
 *
 * Derived from the account type rather than stored, because the two can never legitimately
 * disagree: an income account on the balance sheet is not a configuration, it is a mistake.
 */
enum FinancialStatement: string
{
    case BalanceSheet = 'balance_sheet';
    case ProfitAndLoss = 'profit_and_loss';

    public function label(): string
    {
        return match ($this) {
            self::BalanceSheet => 'Balance sheet',
            self::ProfitAndLoss => 'Profit and loss',
        };
    }
}
