<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Resources;

use Asids\Core\Sales\Domain\Models\TaxCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxCode
 */
final class TaxCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,

            'code' => $this->code,
            'name' => $this->name,

            'tax_type' => $this->tax_type->value,
            'tax_type_label' => $this->tax_type->label(),

            // A percentage, never a factor: 18.0000 means 18%. The percentage↔factor conversion
            // stays with the arithmetic that uses it (`TaxCode::rateFactor()`), not with
            // presentation.
            'rate' => $this->rate,

            'output_account_id' => $this->output_account_id,
            'input_account_id' => $this->input_account_id,

            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_open_ended' => $this->isOpenEnded(),

            'notes' => $this->notes,
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            'capabilities' => [
                'can_update' => $request->user()?->can('update', $this->resource) ?? false,
                'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'charges_tax' => $this->chargesTax(),
            ],
        ];
    }
}
