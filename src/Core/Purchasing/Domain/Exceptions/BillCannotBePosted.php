<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The bill cannot be turned into a balanced journal entry — the payable-side mirror of `InvoiceCannotBePosted`.
 *
 * Every case here is a configuration problem rather than a bug: an account archived since the draft was
 * written, a tax code whose input account was never set, an expense account pointing at another company's
 * ledger. The messages name the account and the remedy, because the person who hits this is the person who has
 * to fix the configuration.
 *
 * Raised before anything is written. The posting map produces journal lines and posts nothing, so a failure
 * here costs no document number and leaves no partial entry.
 */
final class BillCannotBePosted extends BusinessRuleViolation
{
    public static function withoutLines(): self
    {
        return new self(
            'This bill has no lines, so there is nothing to post. Add at least one line first.',
            'bill-has-no-lines',
        );
    }

    /**
     * No payable account could be found at all.
     *
     * Reachable only when a company has no `trade_payables` system account — which means its chart was never
     * provisioned or the backfill did not reach it. The remedy names the provisioning step rather than an
     * account code, because the code differs per company.
     */
    public static function withoutPayableAccount(string $companyName): self
    {
        return new self(
            sprintf(
                '%s has no Trade Payables account, so a bill has nowhere to post its credit. Provision the '
                .'company\'s system accounts.',
                $companyName,
            ),
            'payable-account-missing',
            ['company' => $companyName],
        );
    }

    /**
     * An account belongs to a different company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the
     * policy is satisfied by either one's accounts. Only comparing the company stops a bill posting into a
     * sibling's ledger.
     */
    public static function accountOutsideCompany(string $role, string $accountId): self
    {
        return new self(
            sprintf(
                'The %s account named by this bill belongs to a different company. A bill can only post to its '
                .'own company\'s ledger.',
                $role,
            ),
            'posting-account-outside-company',
            ['role' => $role, 'account_id' => $accountId],
        );
    }

    /**
     * The account exists and belongs to the company, but will not accept a posting.
     *
     * Almost always an account archived or made non-postable after the draft was written.
     */
    public static function accountNotPostable(string $role, Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) no longer accepts postings, so this bill cannot use it as its %s account. '
                .'Reactivate it, or change the bill.',
                $account->code,
                $account->name,
                $role,
            ),
            'posting-account-not-postable',
            ['role' => $role, 'account' => $account->code],
        );
    }

    /**
     * The payable account is not a liability.
     *
     * Reached only when `2110` was reclassified after provisioning typed it a liability. Trade payables
     * credited to anything else would misstate what the company owes.
     */
    public static function payableAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. Trade payables is what the company owes, so it has to be a liability.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'payable-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    public static function expenseAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. A bill line debits expense, so it has to be an expense account.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'expense-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * The tax code's input account is not an asset.
     *
     * Input VAT is recoverable from the authority — an asset. Debited elsewhere, the recovery would be
     * misstated. The input-side counterpart of output VAT's liability check.
     */
    public static function taxAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. Input VAT is recoverable from the authority, so it has to post to an '
                .'asset.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'tax-input-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * A line carries tax but its code has no input account.
     *
     * THE refusal this wave exists to add (AC-3.7). The tax has been charged by the supplier and there is
     * nowhere to record the recoverable input. Refused rather than dropped: posting the expense without the
     * input would balance only because the credit was computed from a total that included it. Most tenants'
     * VAT codes have this null on day one, so the message names the code and the remedy.
     */
    public static function taxCodeHasNoInputAccount(string $taxCode): self
    {
        return new self(
            sprintf(
                'Tax code %s charges tax on this bill but has no input VAT account, so the recoverable tax has '
                .'nowhere to post. Set its input VAT account before posting.',
                $taxCode,
            ),
            'tax-input-account-missing',
            ['tax_code' => $taxCode],
        );
    }

    /**
     * A line carries a tax amount but names no tax code.
     *
     * The database refuses a non-zero `tax_rate` without a code, but not a non-zero `tax_amount` — so this
     * closes the one shape that could otherwise reach posting with tax nobody can attribute.
     */
    public static function taxWithoutCode(int $lineNumber): self
    {
        return new self(
            sprintf(
                'Line %d carries tax but names no tax code, so the recoverable input cannot be attributed to an '
                .'account.',
                $lineNumber,
            ),
            'tax-without-code',
            ['line' => $lineNumber],
        );
    }
}
