<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Resources;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalEntry
 */
final class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'journal_id' => $this->journal_id,
            'fiscal_period_id' => $this->fiscal_period_id,

            'number' => $this->number,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),

            'entry_date' => $this->entry_date->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by_id' => $this->posted_by_id,

            'reverses_entry_id' => $this->reverses_entry_id,
            'reversed_by_entry_id' => $this->reversed_by_entry_id,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,

            /*
             * What this user can do with this entry *right now* — two questions, deliberately asked
             * separately.
             *
             * The gate answers "may this person post entries in this company". The entry answers
             * "is posting meaningful in this state". Both must hold, and the gate alone will not do
             * it, because `Gate::before` grants a tenant owner every ability outright: the status
             * guards inside `JournalEntryPolicy` are unreachable for an owner, so asking the gate on
             * its own reports that an owner may post an entry that is already posted.
             *
             * The ledger is not at risk either way — the posting service refuses the transition and
             * a database trigger refuses the write. This is about not offering a customer a button
             * that can only produce an error.
             */
            'capabilities' => [
                'can_update' => $this->isEditable() && ($request->user()?->can('update', $this->resource) ?? false),
                'can_post' => $this->isEditable() && ($request->user()?->can('post', $this->resource) ?? false),
                'can_reverse' => $this->isPosted() && ($request->user()?->can('reverse', $this->resource) ?? false),
            ],

            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),

            'period' => $this->whenLoaded('fiscalPeriod', fn (): array => [
                'id' => $this->fiscalPeriod->getKey(),
                'label' => $this->fiscalPeriod->label,
                'status' => $this->fiscalPeriod->status->value,
            ]),
        ];
    }
}
