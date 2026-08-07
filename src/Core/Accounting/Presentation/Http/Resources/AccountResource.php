<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Resources;

use Asids\Core\Accounting\Domain\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'parent_id' => $this->parent_id,

            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            // Derived server-side rather than left for the client to infer from the type. A front end
            // that reimplemented this mapping would be a second place for it to be wrong, and the
            // symptom would be a report with every sign inverted.
            'normal_balance' => $this->normal_balance->value,
            'statement' => $this->statement()->value,
            'is_permanent' => $this->isPermanent(),

            'is_postable' => $this->is_postable,
            'is_system' => $this->is_system,
            'system_key' => $this->system_key,
            'is_active' => $this->is_active,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
            'template_version' => $this->template_version,

            // What the client needs to decide which controls to render, answered by the same policy
            // the request will be checked against.
            'capabilities' => [
                'can_update' => $request->user()?->can('update', $this->resource) ?? false,
                'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'accepts_postings' => $this->acceptsPostings(),
            ],

            'children' => AccountResource::collection($this->whenLoaded('children')),
        ];
    }
}
