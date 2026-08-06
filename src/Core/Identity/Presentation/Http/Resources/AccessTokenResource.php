<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Resources;

use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
final class AccessTokenResource extends JsonResource
{
    /**
     * The token hash is never serialised. The plaintext is returned exactly once, by the create
     * endpoint, outside this resource — a token that can be re-read is a token that lives in
     * every log and browser cache that ever saw the list endpoint.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'abilities' => $this->abilities ?? [],

            'is_usable' => $this->isUsable(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,

            'allowed_ip_ranges' => $this->allowed_ip_ranges ?? [],

            // "Never used" is the signal that an integration was configured and forgotten,
            // which is exactly the token worth revoking.
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_ip' => $this->last_used_ip,

            'created_ip' => $this->created_ip,
            'created_at' => $this->created_at?->toIso8601String(),

            'created_by' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->getKey(),
                'full_name' => $this->creator->fullName(),
            ]),
        ];
    }
}
