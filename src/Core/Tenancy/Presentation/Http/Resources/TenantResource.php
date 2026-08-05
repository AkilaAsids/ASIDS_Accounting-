<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Presentation\Http\Resources;

use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * Commercial fields (plan, trial, suspension reason) are exposed only to
     * platform staff. A tenant's own users see the workspace, not the contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPlatformStaff = $request->user()?->is_platform_admin === true;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'country_code' => $this->country_code,
            'currency_code' => $this->currency_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'primary_url' => $this->primaryUrl(),
            'limits' => [
                'companies' => $this->companyLimit(),
                'users' => $this->userLimit(),
            ],
            'trial' => [
                'active' => $this->isOnTrial(),
                'ends_at' => $this->trial_ends_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),

            ...$isPlatformStaff ? [
                'plan_code' => $this->plan_code,
                'subscription_ends_at' => $this->subscription_ends_at?->toIso8601String(),
                'suspended_at' => $this->suspended_at?->toIso8601String(),
                'suspension_reason' => $this->suspension_reason,
                'contact' => [
                    'name' => $this->contact_name,
                    'email' => $this->contact_email,
                    'phone' => $this->contact_phone,
                ],
            ] : [],
        ];
    }

    private function primaryUrl(): string
    {
        $scheme = app()->environment('local') ? 'http' : 'https';

        // `relationLoaded` guard: this resource is rendered in a list of a hundred
        // tenants in the back office, and touching an unloaded relation there is
        // the classic N+1.
        $host = $this->relationLoaded('domains')
            ? ($this->primaryDomain()?->domain ?? $this->fallbackHost())
            : $this->fallbackHost();

        return $scheme.'://'.$host;
    }

    private function fallbackHost(): string
    {
        return $this->slug.'.'.config('asids.tenancy.central_domain');
    }
}
