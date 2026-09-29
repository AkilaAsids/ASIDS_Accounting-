<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The payment cannot be cancelled — the refusals of `PaymentService::cancel()` (ADR 0023 §B, §F).
 *
 * The settlement-side mirror of a sales invoice cancellation's refusals (ADR 0009). A cancellation reverses a
 * posted payment and restores the bills it settled; these guard the request (already cancelled, no reason,
 * cross-company, a settled bill since cancelled) and the timing (today's period closed). The reversal's own
 * ledger authority (`PostedEntryIsImmutable`, closed-period from `PostingService`) sits behind these readable
 * pre-checks. Each factory carries a stable problem code.
 */
final class PaymentCannotBeCancelled extends BusinessRuleViolation
{
    /**
     * The payment has already been cancelled — its posting is already reversed (ADR 0023 §B1).
     */
    public static function alreadyCancelled(string $identifier): self
    {
        return new self(
            sprintf('Payment %s has already been cancelled and cannot be cancelled again.', $identifier),
            'payment-already-cancelled',
            ['payment' => $identifier],
        );
    }

    /**
     * No reason was given. A reversal nobody explained is one an auditor has to chase (ADR 0023 §D6).
     */
    public static function reasonRequired(): self
    {
        return new self(
            'A cancellation needs a reason — it is recorded on both the payment and its reversing entry.',
            'cancellation-reason-required',
        );
    }

    /**
     * The payment belongs to a different company (or does not exist for this one).
     */
    public static function outsideCompany(string $identifier): self
    {
        return new self(
            'The payment named for cancellation does not belong to this company.',
            'payment-outside-company',
            ['payment' => $identifier],
        );
    }

    /**
     * Today's period — the reversal's, not the original payment's — is closed or locked (ADR 0023 §B2, D1). A
     * payment from a closed month may still be cancelled; what refuses it is today being closed.
     */
    public static function periodNotOpen(string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'A cancellation posts its reversal today, but %s is %s. Reopen the period to cancel this payment.',
                $periodLabel,
                strtolower($status->label()),
            ),
            'payment-cancel-period-not-open',
            ['period' => $periodLabel, 'status' => $status->value],
        );
    }

    /**
     * The payment's ledger entry is not in a reversible state — already reversed, or otherwise not posted (ADR
     * 0023 §B). Compared to `Posted` explicitly rather than `isPosted()`, which is also true of a reversed
     * entry; the readable answer here names the payment rather than the journal entry.
     */
    public static function notReversible(string $identifier, string $entryNumber, string $entryStatus): self
    {
        return new self(
            sprintf('Payment %s cannot be cancelled — its ledger entry %s is %s.', $identifier, $entryNumber, $entryStatus),
            'payment-cancel-not-reversible',
            ['payment' => $identifier, 'entry' => $entryNumber, 'entry_status' => $entryStatus],
        );
    }

    /**
     * A bill this payment settled has itself been cancelled (ADR 0023 §B4, dec. D8). Defensive: bill
     * cancellation does not exist yet, so this is unreachable until it ships — present so it fails closed.
     */
    public static function settledBillCancelled(string $billId): self
    {
        return new self(
            'A bill this payment settled has been cancelled, so the payment cannot be cleanly reversed against it.',
            'payment-cancel-bill-cancelled',
            ['bill' => $billId],
        );
    }
}
