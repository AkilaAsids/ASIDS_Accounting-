<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Resources;

use Asids\Core\Authorization\Domain\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
final class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'module' => $this->module,
            'resource' => $this->resource_name(),
            'action' => $this->action,
            'label' => $this->label,
            'description' => $this->description,
            // Drives the step-up authentication prompt and the warning styling on the
            // permission matrix.
            'is_sensitive' => $this->is_sensitive,
            'sort_order' => $this->sort_order,
        ];
    }

    /**
     * `resource` collides with JsonResource's own `$resource` property, so the column is
     * read explicitly rather than through the magic accessor.
     */
    private function resource_name(): string
    {
        return (string) $this->resource->getAttribute('resource');
    }
}
