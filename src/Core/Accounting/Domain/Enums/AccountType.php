<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Enums;

/**
 * The five classifications every account belongs to.
 *
 * WHY AN ENUM RATHER THAN THE TABLE THE DESIGN PROPOSED
 * -----------------------------------------------------
 * The design document called for an `account_types` table seeded from code and not customer
 * editable. On building it, that is an enum with a join attached: the five classifications are
 * fixed by double-entry bookkeeping itself, not by policy, and no customer will ever add a sixth.
 *
 * This also matches what the rest of the platform already does. `PermissionCatalogue` and
 * `SettingsCatalogue` are both catalogues defined in code and synchronised outward precisely so a
 * capability cannot exist without the code that honours it. An account type behaves the same way:
 * `NormalBalance` and the statement it appears on are branches in code, so a row describing a sixth
 * type would name behaviour that does not exist.
 *
 * The type fixes two things that everything downstream depends on — which side of the ledger
 * increases the account, and which statement it appears on.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /**
     * The side that increases this account.
     *
     * Assets and expenses are debit-normal; liabilities, equity and income are credit-normal. This
     * is the rule the whole trial balance is read through — an asset with a credit balance is
     * either an overdraft or an error, and the report can only say which if it knows which way the
     * account is supposed to lean.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }

    /**
     * Whether the account's balance carries forward across a year end.
     *
     * Balance sheet accounts do; income and expense accounts do not — they are swept into retained
     * earnings by the year-end close so the next year measures its own trading rather than
     * accumulating since the company was founded.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Income, self::Expense => false,
        };
    }

    /**
     * The statement this account appears on.
     */
    public function statement(): FinancialStatement
    {
        return $this->isPermanent()
            ? FinancialStatement::BalanceSheet
            : FinancialStatement::ProfitAndLoss;
    }

    /**
     * Presentation order on a statement. Assets before liabilities before equity, income before
     * expenses — the order every accountant reads a set of books in.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Asset => 10,
            self::Liability => 20,
            self::Equity => 30,
            self::Income => 40,
            self::Expense => 50,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
