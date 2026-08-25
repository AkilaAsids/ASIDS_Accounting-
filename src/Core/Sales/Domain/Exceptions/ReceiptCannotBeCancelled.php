<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * The receipt cannot be cancelled.
 *
 * The receiving-side mirror of `InvoiceCannotBeCancelled` (ADR 0015 §B), kept apart from
 * `ReceiptCannotBeRecorded` and `ReceiptCannotBeAllocated` because the three answer different questions.
 * Recording failures are about a receipt that is not ready; allocation failures are about one invoice a
 * receipt is applied to; these are about a receipt already posted, where the question is whether reversing it
 * is still legitimate.
 *
 * Every case except `wouldReverseBelowZero()` is raised before `PostingService::reverse()` is called, so a
 * refusal writes nothing and consumes no journal voucher number. The `receipt-` prefix on each problem code
 * follows the receipt family's convention (see `ReceiptCannotBeRecorded`/`ReceiptCannotBeAllocated`).
 */
final class ReceiptCannotBeCancelled extends BusinessRuleViolation
{
    /**
     * No reason given.
     *
     * `PostingService::reverse()` takes the reason as a required string and writes it onto the reversing entry,
     * so an empty one would produce a correction in the ledger that explains nothing. Refused at the edge,
     * before any lock is taken or number reserved.
     */
    public static function withoutReason(string $identifier): self
    {
        return new self(
            sprintf(
                'Cancelling receipt %s needs a reason. It is written onto the reversing ledger entry, which is '
                .'where an auditor will look for it.',
                $identifier,
            ),
            'receipt-cancellation-reason-required',
            ['receipt' => $identifier],
        );
    }

    /**
     * Already cancelled.
     *
     * The readable refusal for the ordinary sequential case. It is not the protection against a double
     * cancellation — that is the row lock and the finality trigger, which is what holds when two requests race.
     */
    public static function alreadyCancelled(string $identifier): self
    {
        return new self(
            sprintf('Receipt %s has already been cancelled. Its posting was reversed at that point.', $identifier),
            'receipt-already-cancelled',
            ['receipt' => $identifier],
        );
    }

    /**
     * Only a posted receipt may be cancelled.
     *
     * Defensive: no third status is reachable under the two-value CHECK. Written now (Gate-1 #5) so the rule
     * exists before any future state it would guard against does, mirroring `InvoiceCannotBeCancelled::notIssued()`.
     */
    public static function notPosted(string $identifier, string $status): self
    {
        return new self(
            sprintf(
                'Receipt %s is %s, so there is nothing to cancel. Only a posted receipt has a ledger entry to '
                .'reverse.',
                $identifier,
                $status,
            ),
            'receipt-not-posted',
            ['receipt' => $identifier, 'status' => $status],
        );
    }

    /**
     * The receipt is posted but names no journal entry.
     *
     * A posted receipt always carries its `journal_entry_id` from insert, so a null one means the row was
     * written outside the service. Refused rather than repaired: reversing requires knowing what was posted.
     */
    public static function withoutJournalEntry(string $identifier): self
    {
        return new self(
            sprintf(
                'Receipt %s is posted but has no ledger entry, so there is nothing to reverse. This receipt '
                .'needs investigating rather than cancelling.',
                $identifier,
            ),
            'receipt-journal-entry-missing',
            ['receipt' => $identifier],
        );
    }

    /**
     * The entry belongs to another company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the
     * policy is satisfied by either one's entries. Reaching this means the receipt points at a sibling's
     * ledger, and reversing there would take money out of the wrong company's books.
     */
    public static function journalEntryOutsideCompany(string $identifier): self
    {
        return new self(
            sprintf(
                'Receipt %s points at a ledger entry belonging to a different company. Cancelling would reverse '
                .'a posting in the wrong company\'s books.',
                $identifier,
            ),
            'receipt-journal-entry-outside-company',
            ['receipt' => $identifier],
        );
    }

    /**
     * The entry is not in a state that can be reversed.
     *
     * Already reversed, or still a draft. `PostingService::reverse()` refuses both itself; checking here means
     * the caller gets an answer about the *receipt* rather than about a journal entry they did not know
     * existed.
     */
    public static function journalEntryNotReversible(string $identifier, string $entryNumber, string $status): self
    {
        return new self(
            sprintf(
                'Receipt %s cannot be cancelled: its ledger entry %s is %s. An entry can only be reversed once, '
                .'and only after it has been posted.',
                $identifier,
                $entryNumber,
                $status,
            ),
            'receipt-journal-entry-not-reversible',
            ['receipt' => $identifier, 'entry' => $entryNumber, 'entry_status' => $status],
        );
    }

    /**
     * The period the reversal would land in is not open.
     *
     * Note which period this is about: the *reversal's*, not the receipt's. A correction is posted in the
     * current open period rather than backdated into the one being corrected. So a receipt from a closed month
     * may still be cancelled today; what stops a cancellation is today's period being closed.
     */
    public static function intoClosedPeriod(string $identifier, string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'Receipt %s cannot be cancelled because %s, where the reversal would be posted, is %s. Reopen '
                .'that period, or wait until the next one is open.',
                $identifier,
                $periodLabel,
                strtolower($status->label()),
            ),
            'receipt-reversal-period-not-open',
            ['receipt' => $identifier, 'period' => $periodLabel, 'status' => $status->value],
        );
    }

    /**
     * The reversal would drive an invoice's `amount_paid` below zero.
     *
     * The defensive negative-balance guard (§C, AC-C2.7). Subtracting this receipt's own allocation from the
     * invoice's current locked `amount_paid` should never go negative — every allocation was `<= amount_due`
     * when it posted and nothing lowers `amount_paid` except this same path — so reaching it means a bug or an
     * out-of-band write. Refused rather than writing a negative balance to the ledger.
     */
    public static function wouldReverseBelowZero(string $invoiceIdentifier, string $currentPaid, string $allocation): self
    {
        return new self(
            sprintf(
                'Cancelling would subtract %s from invoice %s, which has only %s recorded as paid — driving its '
                .'balance below zero. This invoice needs investigating rather than the receipt cancelling.',
                $allocation,
                $invoiceIdentifier,
                $currentPaid,
            ),
            'receipt-would-reverse-below-zero',
            ['invoice' => $invoiceIdentifier, 'amount_paid' => $currentPaid, 'allocation' => $allocation],
        );
    }
}
