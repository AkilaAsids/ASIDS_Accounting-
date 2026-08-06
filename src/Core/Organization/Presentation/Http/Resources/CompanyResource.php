<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Resources;

use Asids\Core\Organization\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'display_name' => $this->displayName(),
            'code' => $this->code,
            'slug' => $this->slug,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_default' => $this->is_default,
            'archived_at' => $this->archived_at?->toIso8601String(),

            'accounting' => [
                'base_currency_code' => $this->base_currency_code,
                'currency_precision' => $this->currency_precision,
                'fiscal_year_start_month' => $this->fiscal_year_start_month,
                'fiscal_year_start_day' => $this->fiscal_year_start_day,
                'uses_calendar_fiscal_year' => $this->usesCalendarFiscalYear(),
                // Resolved server-side so the SPA never has to reimplement the fiscal
                // calendar — the single most error-prone calculation to duplicate, and the
                // one where a mismatch silently puts a report in the wrong year.
                'current_fiscal_year' => [
                    'starts_on' => $this->fiscalYearStartFor($now)->toDateString(),
                    'ends_on' => $this->fiscalYearEndFor($now)->toDateString(),
                ],
            ],

            'registrations' => [
                'registration_number' => $this->registration_number,
                'tax_identification_number' => $this->tax_identification_number,
                'vat_registration_number' => $this->vat_registration_number,
                'svat_registration_number' => $this->svat_registration_number,
                'is_vat_registered' => $this->is_vat_registered,
                'is_svat_registered' => $this->is_svat_registered,
            ],

            'locale' => [
                'country_code' => $this->country_code,
                'timezone' => $this->timezone,
                'locale' => $this->locale,
            ],

            'contact' => [
                'email' => $this->email,
                'phone' => $this->phone,
                'website' => $this->website,
                'address_line_1' => $this->address_line_1,
                'address_line_2' => $this->address_line_2,
                'city' => $this->city,
                'district' => $this->district,
                'postal_code' => $this->postal_code,
            ],

            'business_type' => $this->business_type,
            'industry' => $this->industry,
            'established_on' => $this->established_on?->toDateString(),
            'logo_path' => $this->logo_path,

            'primary_branch' => $this->when(
                $this->relationLoaded('branches'),
                fn (): ?array => $this->primaryBranch() === null ? null : [
                    'id' => $this->primaryBranch()->getKey(),
                    'name' => $this->primaryBranch()->name,
                    'code' => $this->primaryBranch()->code,
                ],
            ),

            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'branch_count' => $this->whenCounted('branches'),
            'member_count' => $this->whenCounted('memberships'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
