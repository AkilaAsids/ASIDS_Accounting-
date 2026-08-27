<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The receipt cannot be turned into a balanced journal entry.
 *
 * Raised by `ReceiptPostingMap`, which is pure — it reads a receipt, resolves the bank and receivable
 * accounts, and returns journal lines, posting nothing. The cases here are the ones the receipt owns: a bank
 * account that is not a postable, in-company asset, no allocations at all, or the case AC-3.2 names —
 * allocations spanning invoices that resolve to *different* receivable accounts, which must be refused rather
 * than mis-posted. Receivable-side configuration problems (reclassified, non-postable, unprovisioned) are
 * refused by the reused `InvoicePostingMap::receivableAccountFor()` as `InvoiceCannotBePosted`, so they are
 * deliberately not duplicated here.
 *
 * The named-factory shape mirrors `InvoiceCannotBePosted`, and for the same reason: a caller gets a message
 * that names the account and the remedy, never a raw `QueryException` or a constraint name.
 */
final class ReceiptCannotBePosted extends BusinessRuleViolation
{
    public static function withoutAllocations(): self
    {
        return new self(
            'This receipt has no allocations, so there is nothing to post it against. Allocate it to at least '
            .'one invoice first.',
            'receipt-has-no-allocations',
        );
    }

    /**
     * An account belongs to a different company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the
     * policy is satisfied by either one's accounts. Only comparing the company stops a receipt posting into a
     * sibling's ledger.
     */
    public static function accountOutsideCompany(string $role, string $accountId): self
    {
        return new self(
            sprintf(
                'The %s account named by this receipt belongs to a different company. A receipt can only post '
                .'to its own company\'s ledger.',
                $role,
            ),
            'receipt-posting-account-outside-company',
            ['role' => $role, 'account_id' => $accountId],
        );
    }

    public static function accountNotPostable(string $role, Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) no longer accepts postings, so this receipt cannot use it as its %s account. '
                .'Reactivate it, or change the receipt.',
                $account->code,
                $account->name,
                $role,
            ),
            'receipt-posting-account-not-postable',
            ['role' => $role, 'account' => $account->code],
        );
    }

    public static function bankAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. Money received lands in an asset account, so a receipt has to debit '
                .'one.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'receipt-bank-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    // NOTE: the receivable side has no type/postability/missing factories of its own here, deliberately. The
    // map resolves the receivable by reusing `InvoicePostingMap::receivableAccountFor()` (ADR 0014 §C), so a
    // reclassified, non-postable or unprovisioned receivable already refuses there — as `InvoiceCannotBePosted`
    // — and duplicating those cases on this class would be dead code that never throws. What is genuinely the
    // receipt's own is the AC-3.2 conflict below, which the invoice map has no notion of.

    /**
     * The allocated invoices resolve to more than one receivable account (AC-3.2).
     *
     * A receipt is single-customer and one customer resolves to one receivable account, so this does not
     * arise through the validated service path. It is reachable only by allocating across invoices whose
     * customers resolved to different accounts — the case the requirements flag as "must be refused rather
     * than silently mis-posted." Refused rather than splitting the credit or picking one, either of which
     * would leave a control account uncleared.
     */
    public static function receivableAccountsDiffer(int $distinct): self
    {
        return new self(
            sprintf(
                'This receipt\'s allocations resolve to %d different Trade Receivables accounts. A receipt '
                .'credits one control account, so it cannot be posted while its invoices disagree — split it '
                .'into one receipt per receivable account.',
                $distinct,
            ),
            'receipt-receivable-accounts-differ',
            ['distinct' => $distinct],
        );
    }

    /**
     * The company has no Customer Advances account to hold a remainder (ADR 0016 §A).
     *
     * A receipt that leaves a remainder credits `Customer Advances`, resolved by system key. Every company gets
     * one from the template or the backfill, so this is reachable only if that account was removed or never
     * provisioned — the mirror of `InvoiceCannotBePosted::withoutReceivableAccount()` on the receipt side.
     */
    public static function withoutCustomerAdvancesAccount(): self
    {
        return new self(
            'This company has no Customer Advances account, so the unallocated remainder of this receipt has '
            .'nowhere to land. Provision the Customer Advances system account, then record the receipt.',
            'receipt-without-customer-advances-account',
        );
    }
}
