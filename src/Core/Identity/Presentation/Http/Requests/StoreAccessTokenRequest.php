<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue a personal access token for an integration.
 */
final class StoreAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PersonalAccessToken::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],

            // Abilities must be requested explicitly and are intersected with the issuing
            // user's own permissions by AccessTokenService — a token can never be more
            // privileged than the person who created it.
            'abilities' => ['required', 'array', 'min:1', 'max:100'],
            'abilities.*' => ['string', Rule::in(PermissionCatalogue::tenantGrantableNames())],

            // Days, not a date: an integration owner thinks in "rotate every 90 days", and a
            // supplied date in the past would silently create a dead token.
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],

            'allowed_ip_ranges' => ['nullable', 'array', 'max:20'],
            // Both a bare address and a CIDR block are accepted; PersonalAccessToken matches
            // on the packed binary form, so IPv4 and IPv6 both work.
            'allowed_ip_ranges.*' => ['string', 'max:64', 'regex:#^[0-9a-fA-F:.]+(/\d{1,3})?$#'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.required' => 'A token must be granted at least one ability. Tokens are not granted permissions by default.',
            'allowed_ip_ranges.*.regex' => 'Each entry must be an IP address or a CIDR block, for example 203.0.113.4 or 203.0.113.0/24.',
        ];
    }
}
