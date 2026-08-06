<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Resources;

use Asids\Core\Identity\Domain\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoginHistory
 */
final class LoginHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outcome' => $this->outcome->value,
            'succeeded' => $this->outcome->isSuccessful(),
            // The internal reason code is exposed rather than a sentence, so the SPA can
            // translate it — a Sinhala or Tamil user should not read English audit rows.
            'failure_reason' => $this->failure_reason,
            'channel' => $this->channel,

            'ip_address' => $this->ip_address,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'device_type' => $this->device_type,
            'platform' => $this->platform,
            'browser' => $this->browser,

            'two_factor_used' => $this->two_factor_used,
            'two_factor_method' => $this->two_factor_method,

            'logged_out_at' => $this->logged_out_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->getKey(),
                'full_name' => $this->user->fullName(),
                'email' => $this->user->email,
            ]),
        ];
    }
}
