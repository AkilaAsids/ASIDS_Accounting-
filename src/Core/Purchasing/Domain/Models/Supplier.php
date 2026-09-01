<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Domain\Models;

use Asids\Core\Audit\Domain\Concerns\Auditable;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Purchasing\Domain\Enums\SupplierStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A party a company buys from.
 *
 * The payable-side mirror of `Customer`. Audited, because who a company pays and on what terms is
 * exactly the sort of change an auditor asks about — a payment term quietly extended, a tax
 * identification number changed. `Auditable` records the before and after of each.
 *
 * Soft-deleted rather than hard-deleted, and even that is refused by the service once the supplier has
 * bills: a bill is a statutory record and it names its supplier, so the record has to outlive the
 * relationship.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_identification_number
 * @property string|null $vat_registration_number
 * @property bool $is_vat_registered
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property int $payment_terms_days
 * @property string|null $notes
 * @property SupplierStatus $status
 * @property CarbonImmutable|null $archived_at
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Company $company
 * @property-read Branch|null $branch
 */
final class Supplier extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const string MORPH_ALIAS = 'supplier';

    protected $table = 'suppliers';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'tax_identification_number',
        'vat_registration_number',
        'is_vat_registered',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'district',
        'postal_code',
        'country_code',
        'notes',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Whether a new bill may name this supplier.
     */
    public function acceptsNewBills(): bool
    {
        return $this->status->acceptsNewBills();
    }

    public function isArchived(): bool
    {
        return $this->status === SupplierStatus::Archived;
    }

    /**
     * The due date for a bill dated as given, from this supplier's terms.
     *
     * Here rather than on the bill because the terms belong to the supplier, and a bill that derived
     * its own due date would have to reach for them anyway.
     */
    public function dueDateFor(CarbonImmutable $billDate): CarbonImmutable
    {
        return $billDate->addDays($this->payment_terms_days);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Suppliers a picker should offer — everything but the archived.
     *
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupplierStatus::Active->value,
            SupplierStatus::Inactive->value,
        ]);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SupplierStatus::Active->value);
    }

    /**
     * Columns worth recording a change to.
     *
     * Diverges from the customer mirror in one respect, confirmed at Gate 2: the tax identification
     * number is included. It is retained for later WHT/compliance, so a changed supplier TIN is
     * precisely the sort of thing an auditor asks about, and the answer has to be in the trail.
     *
     * @return list<string>
     */
    public function auditOnly(): array
    {
        return [
            'code',
            'name',
            'legal_name',
            'tax_identification_number',
            'vat_registration_number',
            'is_vat_registered',
            'payment_terms_days',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['purchasing', 'supplier'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
            'is_vat_registered' => 'boolean',
            'payment_terms_days' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
