<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Resources;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'level' => $this->level,
            'is_system' => $this->is_system,
            'is_owner' => $this->is_owner,
            'is_template' => $this->isTemplate(),

            // The UI needs to know *why* a control is disabled, not merely that it is;
            // sending these avoids the front end re-deriving the same rules and
            // eventually disagreeing with the server.
            'capabilities' => [
                'renameable' => $this->isRenameable(),
                'deletable' => $this->isDeletable(),
                'permissions_editable' => $this->hasEditablePermissions(),
                'grantable_by_current_user' => $actor?->canGrantRole($this->resource) ?? false,
            ],

            // The owner's authority is implicit, so listing pivot rows would understate
            // it. Reporting the whole grantable catalogue is the honest answer.
            'permissions' => $this->is_owner
                ? PermissionCatalogue::tenantGrantableNames()
                : $this->whenLoaded(
                    'permissions',
                    fn (): array => $this->permissions->pluck('name')->all(),
                ),

            'assigned_user_count' => $this->whenCounted('users'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
