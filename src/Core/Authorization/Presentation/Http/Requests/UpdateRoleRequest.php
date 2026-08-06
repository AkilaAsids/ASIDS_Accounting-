<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Requests;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            && ($this->user()?->can('update', $role) ?? false);
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
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::tenantGrantableNames())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $role = $this->route('role');

        // A system role's label is fixed, so the submitted value is replaced with the
        // stored one. Echoing it back rather than rejecting the request means a client
        // that renders a disabled-but-populated field still succeeds, while the label
        // remains unchanged.
        if ($role instanceof Role && $role->is_system) {
            $this->merge(['label' => $role->label]);
        }
    }
}
