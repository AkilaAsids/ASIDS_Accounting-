<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The invoice cannot be turned into a balanced journal entry.
 *
 * Every case here is a configuration problem rather than a bug: an account archived since the draft was written,
 * a tax code whose output account was cleared, a customer pointing at another company's ledger. The messages name
 * the account and the remedy, because the person who hits this is the person who has to fix the configuration.
 *
 * Raised before anything is written. The posting map produces journal lines and posts nothing, so a failure here
 * costs no document number and leaves no partial entry.
 */
final class InvoiceCannotBePosted extends BusinessRuleViolation
{
    public static function withoutLines(): self
    {
        return new self(
            'This invoice has no lines, so there is nothing to post. Add at least one line first.',
            'invoice-has-no-lines',
        );
    }

    /**
     * No receivable account could be found at all.
     *
     * Reachable only when a company has neither a customer-specific account nor the `trade_receivables` system
     * account — which means its chart was never provisioned. The remedy names the provisioning step rather than an
     * account code, because the code differs per company.
     */
    public static function withoutReceivableAccount(string $companyName): self
    {
        return new self(
            sprintf(
                '%s has no Trade Receivables account, so an invoice has nowhere to post its debit. Provision the '
                .'company\'s system accounts, or set a receivable account on the customer.',
                $companyName,
            ),
            'receivable-account-missing',
            ['company' => $companyName],
        );
    }

    /**
     * An account belongs to a different company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the policy
     * is satisfied by either one's accounts. Only comparing the company stops an invoice posting into a sibling's
     * ledger.
     */
    public static function accountOutsideCompany(string $role, string $accountId): self
    {
        return new self(
            sprintf(
                'The %s account named by this invoice belongs to a different company. An invoice can only post to '
                .'its own company\'s ledger.',
                $role,
            ),
            'posting-account-outside-company',
            ['role' => $role, 'account_id' => $accountId],
        );
    }

    /**
     * The account exists and belongs to the company, but will not accept a posting.
     *
     * Almost always an account archived or made non-postable after the draft was written. Worth its own message:
     * the account is right, its state is not.
     */
    public static function accountNotPostable(string $role, Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) no longer accepts postings, so this invoice cannot use it as its %s account. '
                .'Reactivate it, or change the invoice.',
                $account->code,
                $account->name,
                $role,
            ),
            'posting-account-not-postable',
            ['role' => $role, 'account' => $account->code],
        );
    }

    public static function receivableAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. A customer receivable has to be an asset, or every invoice would debit the '
                .'wrong side of the books.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'receivable-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    public static function revenueAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. An invoice line credits revenue, so it has to name an income account.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'revenue-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * The tax code's output account is not a liability.
     *
     * `TaxCodeService` already refuses to configure one that is not, so reaching this means the account was
     * reclassified afterwards — which the chart of accounts permits while the account has no postings, and a
     * brand new output account has none. Checked here as well because output tax is money held on the
     * authority's behalf: credited to income instead, the trial balance still ties and the return is
     * understated, which is the failure that hides longest.
     */
    public static function taxAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. Output tax is owed to the authority, so it has to post to a liability.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'tax-output-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * A line carries tax but its code has no output account.
     *
     * The tax has been charged to the customer and there is nowhere to record the liability. Refused rather than
     * dropped: posting the revenue without the tax would balance only because the debit was computed from a total
     * that included it, and the return would understate what is owed.
     */
    public static function taxCodeHasNoOutputAccount(string $taxCode): self
    {
        return new self(
            sprintf(
                'Tax code %s charges tax on this invoice but has no output tax account, so the liability has '
                .'nowhere to post. Set its output account before issuing.',
                $taxCode,
            ),
            'tax-output-account-missing',
            ['tax_code' => $taxCode],
        );
    }

    /**
     * A line carries a tax amount but names no tax code.
     *
     * The database refuses a non-zero `tax_rate` without a code, but not a non-zero `tax_amount` — so this closes
     * the one shape that could otherwise reach posting with tax nobody can attribute.
     */
    public static function taxWithoutCode(int $lineNumber): self
    {
        return new self(
            sprintf(
                'Line %d carries tax but names no tax code, so the liability cannot be attributed to an account.',
                $lineNumber,
            ),
            'tax-without-code',
            ['line' => $lineNumber],
        );
    }
}
