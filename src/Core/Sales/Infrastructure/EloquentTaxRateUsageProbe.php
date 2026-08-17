<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Infrastructure;

use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The real answer to "has this rate already been applied to something in the books?".
 *
 * Milestone 3 defined this seam and bound `NoTaxRateUsage`, which truthfully reported that no document
 * carrying tax existed. Invoices do now, so two rules `TaxCodeService` has always carried and could never
 * fire start working:
 *
 *   * A used row's **rate and effective range** become immutable. Change 18% to 20% on the row an invoice
 *     already cited and that invoice's tax silently becomes wrong — along with the return it was reported
 *     on. The remedy is a new effective-dated row, never an edit.
 *   * A used row **cannot be deleted**. The document's tax has to stay explicable, which means the row it
 *     cited has to stay resolvable.
 *
 * Nothing in `TaxCodeService` changed. The binding moved.
 *
 * PER ROW, NOT PER CODE
 * ---------------------
 * The identity is `$taxCode->getKey()`, and that is the whole point. `VAT` at 18% until June and 20% from
 * July are two rows sharing a code: the first may be frozen by an invoice while the second is still freely
 * editable. Matching on the code string would lock rates nobody has used, which is exactly the mistake
 * ADR 0006's effective-dating exists to avoid.
 *
 * A DRAFT IS NOT AN ACCOUNTING DOCUMENT
 * ------------------------------------
 * `tax_code_id` is written onto a line the moment a draft is saved, so the naive query would freeze a rate
 * as soon as somebody started typing an invoice. A draft has no number, is not in the ledger and the
 * customer has never seen it — the rate it cites is not yet a historical fact.
 *
 * Everything else counts, including cancelled. Cancellation reverses the posting; it does not remove it.
 * Both entries stay in the books and both cite this rate, so the row that explains them has to stay as it
 * was.
 *
 * The filter is written as `<> 'draft'` rather than as a list of the four statuses that do count. A status
 * added later — and Phase 4 adds payment states — is then frozen by default. For an immutability rule that
 * is the safe direction to be wrong in: over-freezing is an inconvenience, under-freezing silently rewrites
 * history.
 */
final class EloquentTaxRateUsageProbe implements TaxRateUsageProbe
{
    /**
     * `exists()` rather than a count or a fetch: the question is a boolean and PostgreSQL can stop at the
     * first matching row. `sales_invoice_lines.tax_code_id` is indexed for exactly this lookup.
     */
    public function hasBeenApplied(TaxCode $taxCode): bool
    {
        return SalesInvoiceLine::query()
            ->where('tax_code_id', $taxCode->getKey())
            ->whereHas('invoice', static function (Builder $invoice): void {
                $invoice->where('status', '<>', SalesInvoiceStatus::Draft->value);
            })
            ->exists();
    }
}
