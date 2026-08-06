<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Requests;

use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('create', [Branch::class, $company]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['nullable', 'string', 'min:2', 'max:24', 'regex:/^[A-Za-z0-9-]+$/'],
            // Existence is checked against the tenant-scoped users table, so a manager from
            // another workspace cannot be assigned.
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
