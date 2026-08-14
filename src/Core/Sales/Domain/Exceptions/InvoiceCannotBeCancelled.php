<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;

/**
 * The invoice cannot be cancelled.
 *
 * Kept apart from `InvoiceCannotBeIssued` and `InvoiceCannotBePosted` because the three answer different
 * questions. Posting failures are configuration — an account archived, a tax code missing its output account.
 * Issuing failures are about a document that is not ready. These are about a document that has already been
 * given to a customer and posted, where the question is whether undoing it is still legitimate.
 *
 * Every case is raised before `PostingService::reverse()` is called, so a refusal writes nothing and consumes
 * no journal voucher number.
 */
final class InvoiceCannotBeCancelled extends BusinessRuleViolation
{
    /**
     * Only an issued invoice may be cancelled.
     *
     * A draft has nothing to reverse — it consumed no number and posted nothing — so the answer for a draft is
     * deletion, not cancellation. Approved decision B7.
     */
    public static function notIssued(string $identifier, SalesInvoiceStatus $status): self
    {
        return new self(
            sprintf(
                'Invoice %s is %s, so there is nothing to cancel. A draft is deleted rather than cancelled, '
                .'because it has no number and no ledger entry to reverse.',
                $identifier,
                strtolower($status->label()),
            ),
            'invoice-not-issued',
            ['invoice' => $identifier, 'status' => $status->value],
        );
    }

    /**
     * Already cancelled.
     *
     * The readable refusal for the ordinary sequential case. It is not the protection against a double
     * cancellation — that is the row lock and the immutability trigger, which is what holds when two requests
     * race.
     */
    public static function alreadyCancelled(string $identifier): self
    {
        return new self(
            sprintf('Invoice %s has already been cancelled. Its posting was reversed at that point.', $identifier),
            'invoice-already-cancelled',
            ['invoice' => $identifier],
        );
    }

    /**
     * The invoice is issued but names no journal entry.
     *
     * `sales_invoices_draft_has_no_entry_check` permits a null entry only on a draft, so an issued invoice
     * without one means the row was written outside the service. Refused rather than repaired: reversing
     * requires knowing what was posted, and guessing would put a fabricated correction in the ledger.
     */
    public static function withoutJournalEntry(string $identifier): self
    {
        return new self(
            sprintf(
                'Invoice %s is issued but has no ledger entry, so there is nothing to reverse. This invoice '
                .'needs investigating rather than cancelling.',
                $identifier,
            ),
            'invoice-journal-entry-missing',
            ['invoice' => $identifier],
        );
    }

    /**
     * The entry belongs to another company.
     *
     * The check row level security cannot make: two companies in one workspace share a `tenant_id`, so the
     * policy is satisfied by either one's entries. Reaching this means the invoice points at a sibling's
     * ledger, and reversing there would take money out of the wrong company's books.
     */
    public static function journalEntryOutsideCompany(string $identifier): self
    {
        return new self(
            sprintf(
                'Invoice %s points at a ledger entry belonging to a different company. Cancelling would reverse '
                .'a posting in the wrong company\'s books.',
                $identifier,
            ),
            'invoice-journal-entry-outside-company',
            ['invoice' => $identifier],
        );
    }

    /**
     * The entry is not in a state that can be reversed.
     *
     * Already reversed, or still a draft. `PostingService::reverse()` refuses both itself; checking here means
     * the caller gets an answer about the *invoice* rather than about a journal entry they did not know
     * existed.
     */
    public static function journalEntryNotReversible(string $identifier, string $entryNumber, string $status): self
    {
        return new self(
            sprintf(
                'Invoice %s cannot be cancelled: its ledger entry %s is %s. An entry can only be reversed once, '
                .'and only after it has been posted.',
                $identifier,
                $entryNumber,
                $status,
            ),
            'invoice-journal-entry-not-reversible',
            ['invoice' => $identifier, 'entry' => $entryNumber, 'entry_status' => $status],
        );
    }

    /**
     * The period the reversal would land in is not open.
     *
     * Note which period this is about: the *reversal's*, not the invoice's. Approved decision — a correction is
     * posted in the current open period rather than backdated into the one being corrected, which is what
     * closing a period exists to prevent. So an invoice from a closed March may still be cancelled today; what
     * stops a cancellation is today's period being closed.
     */
    public static function intoClosedPeriod(string $identifier, string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'Invoice %s cannot be cancelled because %s, where the reversal would be posted, is %s. Reopen '
                .'that period, or wait until the next one is open.',
                $identifier,
                $periodLabel,
                strtolower($status->label()),
            ),
            'invoice-reversal-period-not-open',
            ['invoice' => $identifier, 'period' => $periodLabel, 'status' => $status->value],
        );
    }

    /**
     * Money has already been received against it.
     *
     * Cancelling would reverse the receivable and strand the payment against a document that no longer claims
     * anything. Payments arrive in Phase 4; until then `amount_paid` is held at zero by a phase-scoped CHECK,
     * so this is unreachable in practice — stated now so the rule exists before the thing it guards against
     * does, rather than being discovered when it is already possible.
     */
    public static function partiallyPaid(string $identifier, string $amountPaid): self
    {
        return new self(
            sprintf(
                'Invoice %s has %s already received against it, so it cannot be cancelled. Refund or reallocate '
                .'the payment first, then cancel.',
                $identifier,
                $amountPaid,
            ),
            'invoice-partially-paid',
            ['invoice' => $identifier, 'amount_paid' => $amountPaid],
        );
    }

    /**
     * No reason given.
     *
     * `PostingService::reverse()` takes the reason as a required string and writes it onto the reversing entry,
     * so an empty one would produce a correction in the ledger that explains nothing. Refused at the edge
     * rather than passed through.
     */
    public static function withoutReason(string $identifier): self
    {
        return new self(
            sprintf(
                'Cancelling invoice %s needs a reason. It is written onto the reversing ledger entry, which is '
                .'where an auditor will look for it.',
                $identifier,
            ),
            'invoice-cancellation-reason-required',
            ['invoice' => $identifier],
        );
    }
}
