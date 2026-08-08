<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Models;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operating location inside a company: a shop, a warehouse, a regional office.
 *
 * A branch is a *dimension* on transactions, not a separate set of books — a company's
 * trial balance is the sum across its branches, and a branch P&L is a filtered report.
 * See ADR 0002 for why.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property string $code
 * @property string|null $manager_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $timezone
 * @property bool $is_primary
 * @property OrganizationStatus $status
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 *
 * `company_id` is a non-nullable foreign key with a database constraint behind it, so the
 * relation is always present. Typing it nullable would force a null check at every call site
 * that could not be reached.
 * @property-read Company $company
 */
final class Branch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'branches';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'manager_id',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'district',
        'postal_code',
        'country_code',
        'timezone',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * A branch inherits its company's locale settings unless it overrides them — a
     * Colombo head office and a Jaffna branch may sit in the same timezone but need
     * different addresses on their documents.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?? $this->company->timezone;
    }

    public function effectiveCountryCode(): string
    {
        return $this->country_code ?? $this->company->country_code;
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrganizationStatus::Active->value);
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'is_primary' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $branch): void {
            // Matches the unique index on `upper(code)` per company.
            $branch->code = strtoupper(trim($branch->code));

            if ($branch->country_code !== null) {
                $branch->country_code = strtoupper(trim($branch->country_code));
            }
        });
    }
}
