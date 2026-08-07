<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Support\Validation\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite a user.
 *
 * No password field: the invitee chooses their own via the signed link, which is what proves
 * they control the address. An administrator who sets a colleague's password knows that
 * colleague's credential, and every action taken with it becomes deniable.
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invite', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // Deliverable, not merely valid — unlike on sign-in — because a typo means the
            // invitation never arrives and the administrator has no way to tell.
            'email' => ['required', ...EmailAddress::deliverable(), 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'locale' => ['nullable', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],

            // Existence and grantability are checked by RoleService, so an attempt to assign a
            // role above the inviter's level produces the accurate escalation error rather than
            // a generic "invalid" message.
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['uuid'],

            'company_ids' => ['present', 'array', 'max:50'],
            'company_ids.*' => ['uuid', 'exists:companies,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
