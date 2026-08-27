<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Catalogue;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Models\Account;

/**
 * A starting chart of accounts a company can be created from.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is not statutory advice, and the product does not claim it is. A starter chart that
 * misclassifies an account teaches a customer to file incorrectly, and they will not discover it
 * until an assessment. Three things follow from that, and all three are implemented rather than
 * documented as intentions:
 *
 *   1. **The disclaimer travels with the template.** `disclaimer()` is part of the payload every
 *      surface returns, so the API response and the selection screen both carry it. A warning that
 *      lives only in documentation is a warning nobody clicking "apply" reads.
 *   2. **The template is versioned, and companies record which version they were built from.**
 *      Without that, a correction six months from now leaves no way to identify the companies built
 *      on the earlier chart.
 *   3. **Tax mappings are not here.** Which account collects output VAT is a *setting*, configured
 *      per company. Fusing the two would make this template statutory, which is exactly what it must
 *      not be.
 *
 * The structure below is a conventional Sri Lankan SME chart, and is a reasonable place to start
 * rather than an authority on where anything belongs.
 */
final class ChartTemplate
{
    /**
     * Bumped whenever the accounts below change in a way that matters — a code, a type, an addition
     * or a removal. Companies store the version they were created from, so a correction can find
     * them.
     */
    public const string VERSION = '2026.08-lk-sme-3';

    /**
     * Shown wherever the template is offered or applied. Not optional, and not abbreviated.
     */
    public static function disclaimer(): string
    {
        return 'This is a starting point, not professional or statutory advice. Account '
            .'classifications affect how your financial statements and tax returns are prepared. '
            .'Have a qualified Sri Lankan accountant review and adjust this chart before relying on '
            .'it for filing.';
    }

    public static function name(): string
    {
        return 'Sri Lankan SME (starter)';
    }

    public static function description(): string
    {
        return 'A conventional chart for a small or medium Sri Lankan business, covering the '
            .'accounts most companies need on day one. Add, rename and renumber freely — nothing '
            .'the platform depends on is tied to these codes.';
    }

    /**
     * The accounts, parents before children.
     *
     * Ordered so a single pass can create them and resolve each parent by the code that preceded it.
     * Headings are not postable: postings belong on leaves, or a subtotal double-counts its own
     * balance alongside its children's.
     *
     * @return list<array{code: string, name: string, type: AccountType, parent: string|null, postable: bool, system: string|null}>
     */
    public static function accounts(): array
    {
        return [
            // ── Assets ──────────────────────────────────────────────────────────
            self::heading('1000', 'Assets', AccountType::Asset),
            self::heading('1100', 'Current Assets', AccountType::Asset, parent: '1000'),
            self::leaf('1110', 'Cash in Hand', AccountType::Asset, parent: '1100'),
            self::leaf('1120', 'Bank Accounts', AccountType::Asset, parent: '1100'),
            self::leaf('1130', 'Trade Receivables', AccountType::Asset, parent: '1100', system: Account::TRADE_RECEIVABLES),
            self::leaf('1140', 'Other Receivables', AccountType::Asset, parent: '1100'),
            self::leaf('1150', 'Inventory', AccountType::Asset, parent: '1100'),
            self::leaf('1160', 'Prepayments', AccountType::Asset, parent: '1100'),
            // Input VAT is an asset — it is recoverable from the authority. Which account the tax
            // engine posts to is configured in settings, not fixed here.
            self::leaf('1170', 'Input VAT Recoverable', AccountType::Asset, parent: '1100'),

            self::heading('1200', 'Non-Current Assets', AccountType::Asset, parent: '1000'),
            self::leaf('1210', 'Property, Plant and Equipment', AccountType::Asset, parent: '1200'),
            self::leaf('1220', 'Accumulated Depreciation', AccountType::Asset, parent: '1200'),
            self::leaf('1230', 'Intangible Assets', AccountType::Asset, parent: '1200'),

            // ── Liabilities ─────────────────────────────────────────────────────
            self::heading('2000', 'Liabilities', AccountType::Liability),
            self::heading('2100', 'Current Liabilities', AccountType::Liability, parent: '2000'),
            self::leaf('2110', 'Trade Payables', AccountType::Liability, parent: '2100'),
            self::leaf('2120', 'Other Payables', AccountType::Liability, parent: '2100'),
            self::leaf('2130', 'Accruals', AccountType::Liability, parent: '2100'),
            self::leaf('2140', 'Output VAT Payable', AccountType::Liability, parent: '2100'),
            // The statutory payroll deductions a Sri Lankan employer withholds and remits. Present
            // as accounts only; the computation arrives with the payroll phase.
            self::leaf('2150', 'PAYE / APIT Payable', AccountType::Liability, parent: '2100'),
            self::leaf('2160', 'EPF Payable', AccountType::Liability, parent: '2100'),
            self::leaf('2170', 'ETF Payable', AccountType::Liability, parent: '2100'),
            // Where a customer overpayment's remainder is held: money received but not yet earned against
            // a document. A system account, resolved by key rather than code (ADR 0016). `2180` is the
            // first free leaf under Current Liabilities and renumbers nothing above it.
            self::leaf('2180', 'Customer Advances', AccountType::Liability, parent: '2100', system: Account::CUSTOMER_ADVANCES),

            self::heading('2200', 'Non-Current Liabilities', AccountType::Liability, parent: '2000'),
            self::leaf('2210', 'Long-Term Borrowings', AccountType::Liability, parent: '2200'),

            // ── Equity ──────────────────────────────────────────────────────────
            self::heading('3000', 'Equity', AccountType::Equity),
            self::leaf('3100', 'Share Capital', AccountType::Equity, parent: '3000'),
            // Both system accounts. Resolved by key rather than code, so renumbering is safe.
            self::leaf('3200', 'Retained Earnings', AccountType::Equity, parent: '3000', system: Account::RETAINED_EARNINGS),
            self::leaf('3300', 'Opening Balance Equity', AccountType::Equity, parent: '3000', system: Account::OPENING_BALANCE_EQUITY),

            // ── Income ──────────────────────────────────────────────────────────
            self::heading('4000', 'Income', AccountType::Income),
            self::leaf('4100', 'Sales Revenue', AccountType::Income, parent: '4000'),
            self::leaf('4200', 'Service Revenue', AccountType::Income, parent: '4000'),
            self::leaf('4900', 'Other Income', AccountType::Income, parent: '4000'),

            // ── Expenses ────────────────────────────────────────────────────────
            self::heading('5000', 'Cost of Sales', AccountType::Expense),
            self::leaf('5100', 'Purchases', AccountType::Expense, parent: '5000'),
            self::leaf('5200', 'Direct Labour', AccountType::Expense, parent: '5000'),

            self::heading('6000', 'Operating Expenses', AccountType::Expense),
            self::leaf('6100', 'Salaries and Wages', AccountType::Expense, parent: '6000'),
            self::leaf('6110', 'EPF / ETF Employer Contribution', AccountType::Expense, parent: '6000'),
            self::leaf('6200', 'Rent', AccountType::Expense, parent: '6000'),
            self::leaf('6300', 'Utilities', AccountType::Expense, parent: '6000'),
            self::leaf('6400', 'Telephone and Internet', AccountType::Expense, parent: '6000'),
            self::leaf('6500', 'Repairs and Maintenance', AccountType::Expense, parent: '6000'),
            self::leaf('6600', 'Professional Fees', AccountType::Expense, parent: '6000'),
            self::leaf('6700', 'Depreciation', AccountType::Expense, parent: '6000'),
            self::leaf('6800', 'Bank Charges', AccountType::Expense, parent: '6000'),
            self::leaf('6900', 'Other Operating Expenses', AccountType::Expense, parent: '6000'),

            self::heading('7000', 'Finance Costs', AccountType::Expense),
            self::leaf('7100', 'Interest Expense', AccountType::Expense, parent: '7000'),

            self::heading('8000', 'Taxation', AccountType::Expense),
            self::leaf('8100', 'Income Tax Expense', AccountType::Expense, parent: '8000'),
        ];
    }

    /**
     * The system accounts any chart must contain, whether or not the template was used.
     *
     * A company created with an empty chart still needs somewhere for the year-end close to put net
     * income. These are created alongside it.
     *
     * @return list<array{code: string, name: string, type: AccountType, parent: string|null, postable: bool, system: string|null}>
     */
    public static function requiredSystemAccounts(): array
    {
        return [
            self::leaf('3200', 'Retained Earnings', AccountType::Equity, system: Account::RETAINED_EARNINGS),
            self::leaf('3300', 'Opening Balance Equity', AccountType::Equity, system: Account::OPENING_BALANCE_EQUITY),
            // Added by Phase 3 Milestone 5. Every sales invoice debits it unless the customer names an
            // account of its own, so a company that skipped the starter chart must still receive it.
            self::leaf('1130', 'Trade Receivables', AccountType::Asset, system: Account::TRADE_RECEIVABLES),
            // Added by ADR 0016. A company that skipped the starter chart must still be able to hold an
            // overpayment, so the remainder account is required whether or not the template was applied.
            self::leaf('2180', 'Customer Advances', AccountType::Liability, system: Account::CUSTOMER_ADVANCES),
        ];
    }

    /**
     * @return array{code: string, name: string, type: AccountType, parent: string|null, postable: bool, system: string|null}
     */
    private static function heading(string $code, string $name, AccountType $type, ?string $parent = null): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'postable' => false, 'system' => null];
    }

    /**
     * @return array{code: string, name: string, type: AccountType, parent: string|null, postable: bool, system: string|null}
     */
    private static function leaf(
        string $code,
        string $name,
        AccountType $type,
        ?string $parent = null,
        ?string $system = null,
    ): array {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'postable' => true, 'system' => $system];
    }
}
