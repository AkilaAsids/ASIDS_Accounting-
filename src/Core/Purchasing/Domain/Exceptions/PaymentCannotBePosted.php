<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The payment cannot be turned into a balanced journal entry — the mirror of `BillCannotBePosted`, inverted for
 * the settlement side (ADR 0020 §B, §C4).
 *
 * Every case is a configuration or timing problem rather than a bug: a bank account archived since it was
 * chosen, a payable account reclassified, a closed period. The messages name the account and the remedy,
 * because the person who hits this is the person who has to fix it.
 *
 * The posting map produces journal lines and posts nothing (§B), so these refusals cost no document number and
 * leave no partial entry — they run before the record-and-post transaction opens.
 */
final class PaymentCannotBePosted extends BusinessRuleViolation
{
    /**
     * The funding account named by the payment is not an Asset.
     *
     * The one refusal with no bill analogue: 8a funds a payment from bank/cash, both Assets (AC-1.9). A
     * non-asset funding source — an overdraft or credit card modelled as a Liability — is out of scope for 8a.
     */
    public static function bankAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. A payment is funded from bank or cash, so its account has to be an asset.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'payment-bank-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * No payable account could be found at all.
     *
     * Reachable only when a company has no `trade_payables` system account — its chart was never provisioned.
     * Mirror `BillCannotBePosted::withoutPayableAccount`.
     */
    public static function withoutPayableAccount(string $companyName): self
    {
        return new self(
            sprintf(
                '%s has no Trade Payables account, so a payment has nowhere to post its debit. Provision the '
                .'company\'s system accounts.',
                $companyName,
            ),
            'payable-account-missing',
            ['company' => $companyName],
        );
    }

    /**
     * The payable account is not a liability.
     *
     * Reached only when `2110` was reclassified after provisioning. Debiting anything else would misstate what
     * the company owes. Mirror `BillCannotBePosted::payableAccountWrongType`.
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

    /**
     * An account belongs to a different company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the
     * policy is satisfied by either one's accounts. Mirror `BillCannotBePosted::accountOutsideCompany`.
     */
    public static function accountOutsideCompany(string $role, string $accountId): self
    {
        return new self(
            sprintf(
                'The %s account named by this payment belongs to a different company. A payment can only post '
                .'to its own company\'s ledger.',
                $role,
            ),
            'posting-account-outside-company',
            ['role' => $role, 'account_id' => $accountId],
        );
    }

    /**
     * The account exists and belongs to the company, but will not accept a posting.
     *
     * Almost always an account archived or made non-postable after it was chosen. Mirror
     * `BillCannotBePosted::accountNotPostable`.
     */
    public static function accountNotPostable(string $role, Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) no longer accepts postings, so this payment cannot use it as its %s account. '
                .'Reactivate it, or choose another account.',
                $account->code,
                $account->name,
                $role,
            ),
            'posting-account-not-postable',
            ['role' => $role, 'account' => $account->code],
        );
    }

    /**
     * The payment date falls in a period that is closed or locked.
     *
     * `PostingService` refuses this too, but only after a document number has been reserved. Checking here is
     * what makes a closed-period refusal cost nothing (AC-2.7). Mirror `BillCannotBePosted::intoClosedPeriod`.
     */
    public static function intoClosedPeriod(string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'This payment is dated in %s, which is %s. Reopen the period, or change the payment date to one '
                .'in an open period.',
                $periodLabel,
                strtolower($status->label()),
            ),
            'payment-period-not-open',
            ['period' => $periodLabel, 'status' => $status->value],
        );
    }
}
