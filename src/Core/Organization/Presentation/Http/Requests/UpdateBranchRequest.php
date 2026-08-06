<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Requests;

use Asids\Core\Organization\Domain\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch
            && ($this->user()?->can('update', $branch) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            // Accepted, but the service refuses a change once the branch has posted activity,
            // because historical document numbers embed the code.
            'code' => ['sometimes', 'string', 'min:2', 'max:24', 'regex:/^[A-Za-z0-9-]+$/'],
            'manager_id' => ['nullable', 'uuid', 'exists:users,id'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:96'],
            'district' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }
}
