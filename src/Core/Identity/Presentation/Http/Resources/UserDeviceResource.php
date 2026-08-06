<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Resources;

use Asids\Core\Identity\Domain\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserDevice
 */
final class UserDeviceResource extends JsonResource
{
    /**
     * The fingerprint hash is never serialised — it is the device credential, and exposing it
     * would let one signed-in session impersonate another device to skip the 2FA challenge.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_type' => $this->device_type,
            'platform' => $this->platform,
            'browser' => $this->browser,

            'is_trusted' => $this->isTrusted(),
            'trusted_at' => $this->trusted_at?->toIso8601String(),
            'trust_expires_at' => $this->trust_expires_at?->toIso8601String(),

            'last_ip_address' => $this->last_ip_address,
            'last_country_code' => $this->last_country_code,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // Lets the UI label the row "this device", which is the difference between a user
            // confidently revoking a stranger's session and nervously revoking nothing.
            'is_current_device' => $this->isCurrentDevice($request),

            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function isCurrentDevice(Request $request): bool
    {
        $cookie = $request->cookie('asids_device');

        return is_string($cookie)
            && hash_equals($this->resource->getAttribute('fingerprint_hash'), hash('sha256', $cookie));
    }
}
