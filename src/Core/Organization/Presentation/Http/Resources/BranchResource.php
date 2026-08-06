<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Resources;

use Asids\Core\Organization\Domain\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
final class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'code' => $this->code,
            'is_primary' => $this->is_primary,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'archived_at' => $this->archived_at?->toIso8601String(),

            'manager' => $this->whenLoaded('manager', fn (): ?array => $this->manager === null ? null : [
                'id' => $this->manager->getKey(),
                'name' => $this->manager->fullName(),
            ]),

            'contact' => [
                'email' => $this->email,
                'phone' => $this->phone,
                'address_line_1' => $this->address_line_1,
                'address_line_2' => $this->address_line_2,
                'city' => $this->city,
                'district' => $this->district,
                'postal_code' => $this->postal_code,
                'country_code' => $this->country_code,
                'timezone' => $this->timezone,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
