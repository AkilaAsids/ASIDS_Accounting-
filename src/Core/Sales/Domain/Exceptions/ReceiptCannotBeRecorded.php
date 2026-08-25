<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The receipt itself is not in a state that may be recorded.
 *
 * Deliberately separate from `ReceiptCannotBeAllocated` — the split is about what has to change. A failure
 * here is a statement about the receipt or the calendar: the amount is zero, the customer belongs to another
 * company, the bank account is not a postable asset, the sum of the allocations does not equal the amount, the
 * period is closed. A failure there is about one *invoice* the receipt is being applied to. Nobody fixes a
 * closed period by looking at an invoice, or a wrong invoice by looking at the receipt.
 *
 * Every case is raised **before** a document number is reserved and before anything is posted, so a refusal
 * costs no number and leaves no partial row. The named-factory shape follows `InvoiceCannotBeIssued`.
 */
final class ReceiptCannotBeRecorded extends BusinessRuleViolation
{
    /**
     * A zero or negative amount.
     *
     * The database backstop is `customer_receipts_amount_positive_check`; this is the readable answer raised
     * first, the discipline `InvoiceCannotBeIssued::withZeroTotal()` follows.
     */
    public static function zeroOrNegativeAmount(string $amount): self
    {
        return new self(
            sprintf(
                'A receipt of %s records no money received. A receipt has to be for a positive amount.',
                $amount,
            ),
            'receipt-amount-not-positive',
            ['amount' => $amount],
        );
    }

    public static function customerOutsideCompany(): self
    {
        return new self(
            'That customer belongs to a different company, or does not exist. A receipt can only be recorded '
            .'against its own company\'s customer.',
            'receipt-customer-outside-company',
        );
    }

    public static function currencyNotBase(string $currency, string $baseCurrency): self
    {
        return new self(
            sprintf(
                'A receipt in %s cannot be recorded: this company keeps its books in %s, and multi-currency '
                .'receipts are a later phase.',
                $currency,
                $baseCurrency,
            ),
            'receipt-currency-not-base',
            ['currency' => $currency, 'base_currency' => $baseCurrency],
        );
    }

    public static function bankAccountOutsideCompany(): self
    {
        return new self(
            'That bank or cash account belongs to a different company, or does not exist. A receipt debits its '
            .'own company\'s asset account.',
            'receipt-bank-account-outside-company',
        );
    }

    public static function bankAccountNotPostable(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) does not accept postings, so a receipt cannot land in it. Reactivate it, or '
                .'name another account.',
                $account->code,
                $account->name,
            ),
            'receipt-bank-account-not-postable',
            ['account' => $account->code],
        );
    }

    public static function bankAccountWrongType(Account $account): self
    {
        return new self(
            sprintf(
                'Account %s (%s) is %s. Money received lands in an asset account, so a receipt has to name '
                .'one — a bank or cash account.',
                $account->code,
                $account->name,
                $account->type->value,
            ),
            'receipt-bank-account-wrong-type',
            ['account' => $account->code, 'type' => $account->type->value],
        );
    }

    /**
     * The allocations do not sum exactly to the receipt amount (Gate-1 #2).
     *
     * Both over- and under-allocation refuse. Under-allocation would leave a remainder that is unallocated
     * credit-on-account — a deferred feature — so accepting it would half-build it; over-allocation would
     * apply more than was received.
     */
    public static function overOrUnderAllocated(string $allocated, string $amount): self
    {
        return new self(
            sprintf(
                'The allocations total %s but the receipt is for %s. A receipt has to be fully allocated — the '
                .'two must be equal — so record it against the invoices it actually pays.',
                $allocated,
                $amount,
            ),
            'receipt-not-fully-allocated',
            ['allocated' => $allocated, 'amount' => $amount],
        );
    }

    /**
     * No allocations at all — a receipt that pays nothing.
     */
    public static function withoutAllocations(): self
    {
        return new self(
            'A receipt has to be allocated to at least one invoice. Recording money against nothing would '
            .'leave it as unallocated credit, which is a later phase.',
            'receipt-has-no-allocations',
        );
    }

    /**
     * The receipt date falls in a period that is closed or locked.
     *
     * Checked here, before any document number is reserved — the same ordering `SalesInvoiceService::issue()`
     * uses, so the one refusal a user hits routinely (a receipt dated in a month closed yesterday) costs
     * nothing.
     */
    public static function intoClosedPeriod(string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'This receipt is dated in %s, which is %s. Reopen the period, or date the receipt in an open '
                .'one.',
                $periodLabel,
                strtolower($status->label()),
            ),
            'receipt-period-not-open',
            ['period' => $periodLabel, 'status' => $status->value],
        );
    }
}
