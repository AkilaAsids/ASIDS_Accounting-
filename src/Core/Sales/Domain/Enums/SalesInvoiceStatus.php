<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Enums;

/**
 * Where a sales invoice stands.
 *
 * All five states exist from the start even though only `Draft` is reachable in Milestone 4. The database
 * CHECK covers all five for the same reason: Milestone 5 adds issuing and cancellation as behaviour, not as
 * a migration, and a status column widened later is a column that has to be widened while rows already
 * depend on its constraint.
 *
 * `Overdue` is deliberately **not** a case. It is derived — an invoice is overdue when it is issued or part
 * paid and its due date has passed — and storing it would need a nightly job to stay true, leaving it wrong
 * between runs. A status that can be stale is worse than one that has to be computed.
 */
enum SalesInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the invoice's contents may still be changed.
     *
     * Only a draft. Once issued, the document has been given to a customer and posted to the ledger, so its
     * lines, dates and amounts are historical facts — a correction is a credit note or a cancellation and
     * reissue, never an edit.
     *
     * Milestone 4 relies on this for its draft-only rules. Milestone 5 adds the database trigger that makes
     * it impossible rather than merely refused.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the invoice has been issued to the customer and posted.
     *
     * True for cancelled as well, and that is not a mistake: a cancelled invoice *was* issued, keeps its
     * number, and keeps its ledger entry alongside the reversal. "Never issued" is `Draft` alone.
     */
    public function hasBeenIssued(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Whether the invoice represents a live receivable.
     *
     * Cancelled is excluded because its reversal has cleared the balance; paid because there is nothing
     * left to collect. Used from Milestone 5 onwards for outstanding-balance reporting.
     */
    public function isCollectable(): bool
    {
        return match ($this) {
            self::Issued, self::PartiallyPaid => true,
            self::Draft, self::Paid, self::Cancelled => false,
        };
    }
}
