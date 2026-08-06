<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Requests;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],

            'permissions' => ['present', 'array'],
            // Validated against the catalogue rather than against the table: the
            // catalogue is the source of truth, and this rejects platform-only
            // capabilities at the boundary instead of relying on the service alone.
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::tenantGrantableNames())],

            // The level is optional; the service clamps whatever arrives to strictly
            // below the actor's own, so a hostile value cannot escalate.
            'level' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => 'One or more of the selected permissions cannot be granted in this workspace.',
        ];
    }
}
