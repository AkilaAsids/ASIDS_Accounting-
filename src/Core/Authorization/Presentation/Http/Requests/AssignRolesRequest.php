<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Presentation\Http\Requests;

use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('assign', [Role::class, $target]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A full replacement, so an empty array is a valid instruction meaning
            // "remove every role". `present` rather than `required` is what allows that.
            'role_ids' => ['present', 'array', 'max:20'],
            // Existence is checked by the service against assignable roles only, so that
            // a platform template id produces a domain error rather than a validation
            // message that confirms the template's existence.
            'role_ids.*' => ['uuid'],
        ];
    }
}
