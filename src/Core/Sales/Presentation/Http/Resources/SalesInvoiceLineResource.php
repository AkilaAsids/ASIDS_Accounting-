<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Resources;

use Asids\Core\Sales\Domain\Models\SalesInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an invoice.
 *
 * Every figure is a decimal string, never a JSON number, for the reason `JournalLineResource` states: a JSON
 * number is an IEEE-754 double in most clients, and an amount that round-trips through one is no longer the
 * amount that was stored — which is the whole reason the columns are `numeric(19,4)`.
 *
 * `tax_rate` is emitted as the snapshot it is, not as a lookup. ADR 0006 made a rate change a new effective-dated
 * row precisely so an invoice issued at 18% still reads 18% afterwards, and a client resolving the code's current
 * rate instead would undo that.
 *
 * `tax_code` is exposed as the **code**, not only the id, because the code is what `SalesInvoiceService` accepts
 * back on a write: which rate applies depends on the invoice date, and only company + code + date identifies the
 * correct row. A client that read an id here and sent it back would be rejected, so the field a caller needs is
 * the one this resource leads with.
 *
 * @mixin SalesInvoiceLine
 */
final class SalesInvoiceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,

            'description' => $this->description,

            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,

            // One or the other, never both — the service refuses a line carrying two discount forms, because a
            // percentage a salesperson negotiated and a fixed amount a manager approved are different claims.
            'discount_percent' => $this->discount_percent === null ? null : (string) $this->discount_percent,
            'discount_amount' => $this->discount_amount === null ? null : (string) $this->discount_amount,

            'line_subtotal' => (string) $this->line_subtotal,

            'tax_code_id' => $this->tax_code_id,
            // The code, which is what a write accepts back. Null when the line carries no tax code at all.
            'tax_code' => $this->whenLoaded('taxCode', fn (): ?string => $this->taxCode?->code),
            'tax_rate' => (string) $this->tax_rate,
            'tax_amount' => (string) $this->tax_amount,

            'line_total' => (string) $this->line_total,

            'revenue_account_id' => $this->revenue_account_id,
            'branch_id' => $this->branch_id,
        ];
    }
}
