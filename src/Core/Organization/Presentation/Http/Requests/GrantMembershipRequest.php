<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Requests;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Illuminate\Foundation\Http\FormRequest;

final class GrantMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        // The target is resolved here rather than in the policy so that a self-grant is
        // refused by authorisation rather than surfacing as a domain error later.
        $target = User::query()->find($this->input('user_id'));

        return $this->user()?->can('grant', [CompanyMembership::class, $company, $target]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            // Optional narrowing to one branch. Ownership of the branch by the company is
            // verified by the service, not here, so the message names the real problem.
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'make_default' => ['nullable', 'boolean'],
        ];
    }
}
