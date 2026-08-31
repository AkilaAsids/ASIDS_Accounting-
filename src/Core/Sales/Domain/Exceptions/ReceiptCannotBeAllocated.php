<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;

/**
 * A receipt cannot be applied to one of the invoices it names.
 *
 * The counterpart to `ReceiptCannotBeRecorded`: every case here is about a single *invoice* in the allocation
 * set, not about the receipt as a whole. An invoice that is a draft, cancelled or already paid; one belonging
 * to another customer or another company; a zero or negative line; or a line larger than the invoice's
 * *current* `amount_due`, re-read under the row lock at the moment of committing — never the figure a user saw
 * on a screen that another receipt has since moved.
 *
 * Named-factory shape, following `InvoiceCannotBeIssued` — a message a user can act on, never a raw constraint.
 */
final class ReceiptCannotBeAllocated extends BusinessRuleViolation
{
    /**
     * The invoice is not a live receivable — it is a draft, cancelled, or already fully paid (AC-2.6).
     */
    public static function toNonCollectableInvoice(string $identifier, SalesInvoiceStatus $status): self
    {
        return new self(
            sprintf(
                'Invoice %s is %s, so a receipt cannot be allocated to it. %s',
                $identifier,
                strtolower($status->label()),
                match ($status) {
                    SalesInvoiceStatus::Draft => 'A draft has not been issued, so nothing is owed against it yet.',
                    SalesInvoiceStatus::Cancelled => 'A cancelled invoice is no longer a receivable.',
                    SalesInvoiceStatus::Paid => 'A paid invoice has nothing left to allocate against.',
                    default => 'Only an issued or partially paid invoice may receive an allocation.',
                },
            ),
            'receipt-allocation-invoice-not-collectable',
            ['invoice' => $identifier, 'status' => $status->value],
        );
    }

    public static function crossCustomer(string $identifier): self
    {
        return new self(
            sprintf(
                'Invoice %s belongs to a different customer than this receipt. A receipt only pays its own '
                .'customer\'s invoices.',
                $identifier,
            ),
            'receipt-allocation-cross-customer',
            ['invoice' => $identifier],
        );
    }

    public static function crossCompany(string $identifier): self
    {
        return new self(
            sprintf(
                'Invoice %s belongs to a different company than this receipt. Two companies in one workspace '
                .'share a tenant, so only this check stops a receipt reaching a sibling\'s invoice.',
                $identifier,
            ),
            'receipt-allocation-cross-company',
            ['invoice' => $identifier],
        );
    }

    public static function zeroOrNegativeLine(string $identifier, string $amount): self
    {
        return new self(
            sprintf(
                'The allocation of %s to invoice %s is not positive. A zero line pays nothing and a negative '
                .'one would un-pay the invoice.',
                $amount,
                $identifier,
            ),
            'receipt-allocation-not-positive',
            ['invoice' => $identifier, 'amount' => $amount],
        );
    }

    /**
     * The line is larger than the invoice's current outstanding balance (AC-2.5).
     *
     * The `amount_due` here is the one read *through the row lock* inside the transaction, not the figure the
     * caller submitted against — which is what makes two receipts racing one invoice safe: the second re-reads
     * the now-lower balance and is refused here rather than racing to the `amount_paid <= total` CHECK.
     */
    public static function exceedsAmountDue(string $identifier, string $amount, string $amountDue): self
    {
        return new self(
            sprintf(
                'The allocation of %s to invoice %s is more than its outstanding balance of %s. Another receipt '
                .'may have been applied since — allocate no more than what is still due.',
                $amount,
                $identifier,
                $amountDue,
            ),
            'receipt-allocation-exceeds-amount-due',
            ['invoice' => $identifier, 'amount' => $amount, 'amount_due' => $amountDue],
        );
    }

    /**
     * The customer holds less credit than the apply requested (ADR 0016 §E, §13).
     *
     * Raised when the FIFO set — or a single named source — cannot satisfy the requested amount. A named source
     * that falls short does *not* spill into other records: naming a source is a deliberate choice to draw from
     * that receipt alone. Refused before anything is written, so the held records stay intact.
     */
    public static function insufficientCredit(string $requested, string $available): self
    {
        return new self(
            sprintf(
                'This customer holds %s of applicable credit, which is less than the %s requested. Apply no more '
                .'than what is held, or record a fresh receipt for the difference.',
                $available,
                $requested,
            ),
            'receipt-insufficient-credit',
            ['requested' => $requested, 'available' => $available],
        );
    }

    /**
     * The withholding tax on an allocation is negative (ADR 0017 §D).
     *
     * The readable refusal ahead of the same-row `wht_amount >= 0` CHECK, following the `zeroOrNegativeLine()`
     * reasoning: a zero withholding is simply "no WHT", but a negative one would fabricate a credit — un-paying
     * the invoice through the ledger.
     */
    public static function negativeWithholding(string $identifier, string $whtAmount): self
    {
        return new self(
            sprintf(
                'The withholding of %s on invoice %s is negative. Withholding tax is never negative — a zero or '
                .'omitted figure means none was withheld.',
                $whtAmount,
                $identifier,
            ),
            'receipt-allocation-withholding-negative',
            ['invoice' => $identifier, 'wht_amount' => $whtAmount],
        );
    }

    /**
     * The withholding tax on an allocation is larger than the gross AR it is withheld against (ADR 0017 §D).
     *
     * The readable refusal ahead of the same-row `wht_amount <= amount` CHECK, mirroring how `exceedsAmountDue()`
     * precedes the invoice-level CHECK. A customer cannot withhold more tax than the gross amount they are
     * settling (Gate-1 #2).
     */
    public static function withholdingExceedsAllocation(string $identifier, string $whtAmount, string $amount): self
    {
        return new self(
            sprintf(
                'The withholding of %s on invoice %s is more than the %s allocated to it. Withholding tax cannot '
                .'exceed the gross amount it is withheld against.',
                $whtAmount,
                $identifier,
                $amount,
            ),
            'receipt-allocation-withholding-exceeds-allocation',
            ['invoice' => $identifier, 'wht_amount' => $whtAmount, 'amount' => $amount],
        );
    }

    /**
     * The named invoice does not exist in this workspace at all.
     */
    public static function unknownInvoice(string $identifier): self
    {
        return new self(
            sprintf('Invoice %s does not exist, so a receipt cannot be allocated to it.', $identifier),
            'receipt-allocation-unknown-invoice',
            ['invoice' => $identifier],
        );
    }
}
