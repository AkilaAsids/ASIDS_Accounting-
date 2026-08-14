<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Exceptions;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;

/**
 * The invoice is not in a state that may be issued.
 *
 * Deliberately separate from `InvoiceCannotBePosted`, and the split is about *what has to change* rather than
 * about where the check happens. A posting failure is a configuration problem — an account archived, a tax code
 * missing its output account — and the remedy is to fix the chart. A failure here is a statement about the
 * document or the calendar: it is already issued, it charges nothing, the month is closed. Nobody fixes those by
 * editing an account.
 *
 * Every case is raised **before** a document number is reserved and before anything is posted, so a refusal costs
 * no number and leaves no partial entry.
 */
final class InvoiceCannotBeIssued extends BusinessRuleViolation
{
    /**
     * Only a draft may be issued.
     *
     * The guard that makes a second `issue()` a clean refusal rather than a constraint violation. It is not the
     * *protection* against double issuance — that is the unique index on `journal_entries.source_id`, which is
     * what holds when two requests race. This is the readable answer for the ordinary sequential case.
     */
    public static function notADraft(string $identifier, SalesInvoiceStatus $status): self
    {
        return new self(
            sprintf(
                'Invoice %s is %s and has already been issued. An issued invoice is a statutory record; correct '
                .'it with a credit note or a cancellation instead.',
                $identifier,
                strtolower($status->label()),
            ),
            'invoice-not-a-draft',
            ['invoice' => $identifier, 'status' => $status->value],
        );
    }

    /**
     * A draft with no lines.
     *
     * `SalesInvoiceService` refuses to *write* a draft without lines, so reaching this means the lines were
     * removed underneath it — a cascade, a direct deletion. Checked anyway: issuing is the moment the document
     * becomes real, and an invoice charging for nothing is not a document a customer can be sent.
     */
    public static function withoutLines(string $identifier): self
    {
        return new self(
            sprintf('Invoice %s has no lines, so there is nothing to charge for. Add at least one line first.', $identifier),
            'invoice-has-no-lines-to-issue',
            ['invoice' => $identifier],
        );
    }

    /**
     * A draft totalling zero.
     *
     * Approved decision B4: a draft may sit at zero while it is being written, and issuing is where that stops
     * being acceptable. A zero invoice cannot be posted at all — `journal_lines_one_sided_check` requires exactly
     * one side to be positive — so without this the failure would surface as a database error naming a constraint
     * the user has never heard of.
     */
    public static function withZeroTotal(string $identifier, string $total): self
    {
        return new self(
            sprintf(
                'Invoice %s totals %s, so there is nothing to post. An invoice has to charge something before it '
                .'can be issued.',
                $identifier,
                $total,
            ),
            'invoice-total-not-positive',
            ['invoice' => $identifier, 'total' => $total],
        );
    }

    /**
     * The invoice date falls in a period that is closed or locked.
     *
     * `PostingService` refuses this too, and would catch it — but only after a document number had been reserved.
     * Checking here is what makes a closed-period refusal cost nothing, which matters because it is the one
     * refusal on this list a user hits routinely: they issue on the 3rd of the month for an invoice dated the
     * 30th of the last one, which the accountant closed yesterday.
     */
    public static function intoClosedPeriod(string $identifier, string $periodLabel, PeriodStatus $status): self
    {
        return new self(
            sprintf(
                'Invoice %s is dated in %s, which is %s. Reopen the period, or change the invoice date to one in '
                .'an open period.',
                $identifier,
                $periodLabel,
                strtolower($status->label()),
            ),
            'invoice-period-not-open',
            ['invoice' => $identifier, 'period' => $periodLabel, 'status' => $status->value],
        );
    }
}
