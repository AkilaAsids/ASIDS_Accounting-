<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Enums;

/**
 * Where a bill stands — the payable-side mirror of `SalesInvoiceStatus`.
 *
 * All five states exist from the start even though only `Draft` and `Posted` are reachable this wave. The
 * database CHECK covers all five for the same reason: cancellation and payments arrive as behaviour, not as a
 * migration, and a status column widened later is a column that has to be widened while rows already depend on
 * its constraint.
 *
 * We *post* a bill (we *issue* an invoice) — hence `Posted`, not `Issued`. `Overdue` is deliberately not a
 * case: it is derived, and storing it would need a nightly job to stay true.
 */
enum BillStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the bill's contents may still be changed.
     *
     * Only a draft. Once posted, the bill has been recorded against the supplier and posted to the ledger, so
     * its lines, dates and amounts are historical facts.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the bill has been posted to the ledger.
     *
     * True for cancelled as well, and that is not a mistake: a cancelled bill *was* posted, keeps its number,
     * and keeps its ledger entry alongside the reversal. "Never posted" is `Draft` alone.
     */
    public function hasBeenPosted(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Whether the bill represents a live payable.
     *
     * The probe's source of truth. Cancelled is excluded because its reversal has cleared the balance; paid
     * because there is nothing left to pay; draft because it is not yet owed.
     */
    public function isOutstanding(): bool
    {
        return match ($this) {
            self::Posted, self::PartiallyPaid => true,
            self::Draft, self::Paid, self::Cancelled => false,
        };
    }
}
