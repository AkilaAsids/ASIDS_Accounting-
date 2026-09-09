<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Purchasing\Domain\Enums\BillStatus;

/**
 * The payment cannot be recorded — the input and allocation refusals of `PaymentService::record()` (ADR 0020
 * §C4). The settlement-side counterpart to the `BusinessRuleViolation` input refusals a bill draws (supplier
 * and bill ownership, membership, outstanding, the per-bill cap, full allocation, positivity, duplicates).
 *
 * The division mirrors bills: `PaymentCannotBeRecorded` for what is wrong with the *request*
 * (supplier/bill/allocation), `PaymentCannotBePosted` for what is wrong with the *ledger targets* (account
 * resolution, closed period). Both extend `BusinessRuleViolation`, so a future HTTP layer maps them uniformly.
 * Each factory carries a stable problem code.
 */
final class PaymentCannotBeRecorded extends BusinessRuleViolation
{
    /**
     * A payment with no allocations (AC-1.2). Full allocation is required, so there is nothing to record.
     */
    public static function withoutAllocations(): self
    {
        return new self(
            'A payment must be allocated to at least one bill. Add an allocation before recording it.',
            'payment-has-no-allocations',
        );
    }

    /**
     * The stated total is zero or less (AC-1.8).
     */
    public static function amountNotPositive(string $amount): self
    {
        return new self(
            sprintf('A payment totals %s, so there is nothing to record. A payment has to carry a positive amount.', $amount),
            'payment-amount-not-positive',
            ['amount' => $amount],
        );
    }

    /**
     * A single allocation is zero or less. A negative allocation would be a refund/debit note — out of scope.
     */
    public static function allocationNotPositive(string $billId, string $amount): self
    {
        return new self(
            sprintf('An allocation of %s to a bill is not positive. Each allocation has to be a positive amount.', $amount),
            'payment-allocation-not-positive',
            ['bill' => $billId, 'amount' => $amount],
        );
    }

    /**
     * The same bill named twice in one payload. The readable message; the unique index
     * `payment_allocations_payment_bill_unique` (§A4) is the authority under a race.
     */
    public static function duplicateBillAllocation(string $billId): self
    {
        return new self(
            'A bill appears more than once in this payment. Combine the amounts into a single allocation per bill.',
            'payment-duplicate-bill-allocation',
            ['bill' => $billId],
        );
    }

    /**
     * The named supplier belongs to a different company (or does not exist for this one).
     */
    public static function supplierOutsideCompany(string $supplierId): self
    {
        return new self(
            'The supplier named by this payment does not belong to this company.',
            'payment-supplier-outside-company',
            ['supplier' => $supplierId],
        );
    }

    /**
     * The named bank/cash account belongs to a different company (ownership only; type/postability are the
     * posting map's job, §B).
     */
    public static function bankAccountOutsideCompany(string $accountId): self
    {
        return new self(
            'The bank or cash account named by this payment does not belong to this company.',
            'payment-bank-account-outside-company',
            ['account' => $accountId],
        );
    }

    /**
     * An allocation names a bill belonging to a different company (AC-1.5). The membership check comes first,
     * so a cross-company bill surfaces as this rather than `billNotForSupplier`.
     */
    public static function billOutsideCompany(string $billId): self
    {
        return new self(
            'An allocation names a bill that belongs to a different company. A payment can only settle its own '
            .'company\'s bills.',
            'payment-bill-outside-company',
            ['bill' => $billId],
        );
    }

    /**
     * An allocation names a bill that is not the payment's supplier's (AC-1.3).
     */
    public static function billNotForSupplier(string $billId): self
    {
        return new self(
            'An allocation names a bill that belongs to a different supplier. A payment settles one supplier\'s '
            .'bills.',
            'payment-bill-not-for-supplier',
            ['bill' => $billId],
        );
    }

    /**
     * An allocation names a bill that is not an outstanding payable (AC-1.4): a draft, a paid or cancelled
     * bill, or one a racing payment cleared under the lock.
     */
    public static function billNotOutstanding(string $identifier, BillStatus $status): self
    {
        return new self(
            sprintf(
                'Bill %s is %s and is not an outstanding payable, so a payment cannot be allocated to it.',
                $identifier,
                strtolower($status->label()),
            ),
            'payment-bill-not-outstanding',
            ['bill' => $identifier, 'status' => $status->value],
        );
    }

    /**
     * The allocations do not sum to the stated total (AC-1.7). Full allocation is required (dec. 2).
     */
    public static function notFullyAllocated(string $amount, string $allocated): self
    {
        return new self(
            sprintf(
                'The allocations total %s but the payment is %s. A payment must be fully allocated — the two have '
                .'to match.',
                $allocated,
                $amount,
            ),
            'payment-not-fully-allocated',
            ['amount' => $amount, 'allocated' => $allocated],
        );
    }

    /**
     * The payment was told to withhold but no rate could be found — neither a per-payment override nor the
     * supplier's `wht_rate` (ADR 0021 §C2).
     */
    public static function whtRateMissing(string $supplier): self
    {
        return new self(
            sprintf('Supplier %s is set to withhold tax but has no rate, and none was supplied for this payment.', $supplier),
            'payment-wht-rate-missing',
            ['supplier' => $supplier],
        );
    }

    /**
     * A supplied WHT rate override is outside [0,100].
     */
    public static function whtRateOutOfRange(string $rate): self
    {
        return new self(
            sprintf('A withholding-tax rate of %s%% is out of range. It must be between 0 and 100.', $rate),
            'payment-wht-rate-out-of-range',
            ['rate' => $rate],
        );
    }

    /**
     * The withheld amount exceeds the payment amount (net cash would be negative). Defensive: the rate range
     * check makes this largely unreachable, and `supplier_payments_wht_within_amount_check` is the DB
     * authority; this makes the net-cash-≥-0 rule explicit (FR-4).
     */
    public static function whtExceedsAmount(string $wht, string $amount): self
    {
        return new self(
            sprintf('The withheld tax of %s exceeds the payment of %s — the net cash would be negative.', $wht, $amount),
            'payment-wht-exceeds-amount',
            ['wht' => $wht, 'amount' => $amount],
        );
    }

    /**
     * An allocation exceeds the bill's current `amount_due` (AC-1.6, the per-bill cap, dec. 3). The readable
     * message; `bills_amount_due_non_negative_check` (§A4) is the authority under concurrency.
     */
    public static function allocationExceedsDue(string $identifier, string $amount, string $due): self
    {
        return new self(
            sprintf(
                'An allocation of %s to bill %s exceeds its outstanding %s. A bill cannot be paid beyond what is '
                .'owed on it.',
                $amount,
                $identifier,
                $due,
            ),
            'payment-allocation-exceeds-due',
            ['bill' => $identifier, 'amount' => $amount, 'due' => $due],
        );
    }
}
