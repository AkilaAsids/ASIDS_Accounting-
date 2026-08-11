<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Contracts;

use Asids\Core\Sales\Domain\Models\TaxCode;

/**
 * Answers "has this rate already been applied to something in the books?".
 *
 * `TaxCodeService` needs that answer to enforce the rule that a rate's accounting meaning cannot be
 * rewritten once used: change 18% to 20% on the row an invoice already cited, and that invoice's tax
 * silently becomes wrong — along with the return it was reported on. The remedy is a new
 * effective-dated row, never an edit.
 *
 * Documents do not exist until Milestone 4, so this is the seam. `NoTaxRateUsage` reports the truth for
 * the current schema: nothing has been invoiced because there is nothing to invoice with.
 *
 * The question is deliberately a business one. It does not accept a date range, an invoice, or anything
 * else shaped like the table that will answer it — the implementation may query `sales_invoice_lines`,
 * or a tax-summary table, or something not yet designed, and the caller must not care. Phase 1's
 * `LedgerActivityProbe` and Milestone 2's `ReceivableBalanceProbe` are the same shape for the same
 * reason.
 *
 * Building the seam now rather than later is what stops the rule being forgotten. A constraint with
 * nothing to enforce it on day one usually never arrives, and "we will block rate edits once invoices
 * land" is exactly the promise that does not get kept.
 */
interface TaxRateUsageProbe
{
    /**
     * Whether any accounting document has applied this specific rate row.
     *
     * Per row, not per code. `VAT` at 18% until June and 20% from July are two rows: the first may be
     * frozen by an invoice while the second is still freely editable, and treating the code as a whole
     * would lock rates nobody has used.
     */
    public function hasBeenApplied(TaxCode $taxCode): bool;
}
