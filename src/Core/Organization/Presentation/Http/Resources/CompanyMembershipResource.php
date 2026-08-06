<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Resources;

use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyMembership
 */
final class CompanyMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'branch_id' => $this->branch_id,
            'is_branch_restricted' => $this->isBranchRestricted(),
            'is_default' => $this->is_default,
            'is_active' => $this->isActive(),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->getKey(),
                'name' => $this->user->fullName(),
                'email' => $this->user->email,
                'status' => $this->user->status->value,
            ]),

            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->getKey(),
                'name' => $this->company->name,
                'code' => $this->company->code,
            ]),

            'granted_by' => $this->whenLoaded('grantedBy', fn (): ?array => $this->grantedBy === null ? null : [
                'id' => $this->grantedBy->getKey(),
                'name' => $this->grantedBy->fullName(),
            ]),
        ];
    }
}
