<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Resources;

use Asids\Core\Sales\Domain\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,

            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,

            'tax_identification_number' => $this->tax_identification_number,
            'vat_registration_number' => $this->vat_registration_number,
            'is_vat_registered' => $this->is_vat_registered,

            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,

            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,

            'payment_terms_days' => $this->payment_terms_days,

            // A decimal string or null — null means unlimited (column comment). Never a float:
            // this is compared against a receivable balance using the same arithmetic the
            // balance does.
            'credit_limit' => $this->credit_limit,

            // Null means the company's system AR default. Resolving that fallback is the
            // service's job, not this resource's.
            'receivable_account_id' => $this->receivable_account_id,

            'notes' => $this->notes,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'archived_at' => $this->archived_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            'capabilities' => [
                'can_update' => $request->user()?->can('update', $this->resource) ?? false,
                'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'accepts_new_invoices' => $this->acceptsNewInvoices(),
            ],
        ];
    }
}
