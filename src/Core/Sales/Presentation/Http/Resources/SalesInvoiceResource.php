<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Resources;

use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sales invoice.
 *
 * Every monetary field is a decimal string at the ledger's scale, every date is `YYYY-MM-DD` and every timestamp
 * is ISO-8601 — the conventions `JournalEntryResource` and `CustomerResource` established.
 *
 * WHAT `capabilities` ANSWERS, AND WHY IT ASKS TWICE
 * -------------------------------------------------
 * "What can this user do with this invoice *right now*" — and that is two questions, deliberately kept apart.
 *
 * The gate answers whether the person holds the capability in this company. The invoice answers whether the
 * operation is meaningful in its current state. Both must hold, and **the gate alone will not do**, because
 * `Gate::before` grants a tenant owner every ability outright: every state guard inside `SalesInvoicePolicy` is
 * short-circuited for an owner, so asking the gate on its own reports that an owner may issue an invoice that is
 * already issued. `JournalEntryResource` carries the same note for the same reason.
 *
 * `can_cancel` tests `status === Issued` rather than the policy's `hasBeenIssued()`. The policy is deliberately
 * looser — a cancelled invoice returns true there — because it answers a *capability* question and leaves the
 * particular invoice to the service, which permits only `Issued`. Copying the policy's predicate here would offer
 * a Cancel button on an already-cancelled invoice, which can only ever produce an error.
 *
 * Nothing here is a security boundary. The service refuses every transition it should regardless of what this
 * reports, and database triggers refuse the writes underneath that. This exists so a client is not offered an
 * action that is certain to fail.
 *
 * @mixin SalesInvoice
 */
final class SalesInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,

            // Null until issued. A database CHECK ties this to the status, so the pair can never disagree.
            'number' => $this->number,
            'reference' => $this->reference,

            'invoice_date' => $this->invoice_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),

            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate === null ? null : (string) $this->exchange_rate,

            'subtotal' => (string) $this->subtotal,
            'discount_total' => (string) $this->discount_total,
            'tax_total' => (string) $this->tax_total,
            'total' => (string) $this->total,
            'amount_paid' => (string) $this->amount_paid,
            'amount_due' => (string) $this->amount_due,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Derived, never stored. "Overdue" is a question about today rather than a fact about the document,
            // and a stored flag would need a nightly job to stay true and be wrong between runs.
            'is_overdue' => $this->isOverdue(),

            'issued_at' => $this->issued_at?->toIso8601String(),
            'issued_by_id' => $this->issued_by_id,
            'journal_entry_id' => $this->journal_entry_id,

            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by_id' => $this->cancelled_by_id,

            'notes' => $this->notes,
            'terms' => $this->terms,

            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            'capabilities' => [
                'can_update' => $this->isEditable() && ($request->user()?->can('update', $this->resource) ?? false),
                'can_delete' => $this->isEditable() && ($request->user()?->can('delete', $this->resource) ?? false),
                'can_issue' => $this->isDraft() && ($request->user()?->can('issue', $this->resource) ?? false),
                'can_cancel' => $this->status === SalesInvoiceStatus::Issued
                    && ($request->user()?->can('cancel', $this->resource) ?? false),
            ],

            'lines' => SalesInvoiceLineResource::collection($this->whenLoaded('lines')),

            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->getKey(),
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ]),
        ];
    }
}
