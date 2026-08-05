<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Models;

use Asids\Core\Tenancy\Domain\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A tenant is one paying customer of ASIDS ERP Cloud.
 *
 * It owns companies, users, roles and every other tenant-scoped record. It is the
 * only model in the platform that is *not* itself tenant scoped, which is why it
 * has no `BelongsToTenant` trait and no row level security policy — it is the root
 * of the isolation hierarchy rather than a participant in it.
 *
 * Real columns are declared in `getCustomColumns()`. Anything not listed there is
 * transparently stored in the `data` JSONB column by stancl/tenancy, which keeps
 * feature flags and onboarding state out of the migration history.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property TenantStatus $status
 * @property string|null $plan_code
 * @property string $country_code
 * @property string $currency_code
 * @property string $timezone
 * @property string $locale
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $provisioned_at
 * @property int|null $max_companies
 * @property int|null $max_users
 */
final class Tenant extends BaseTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'tenants';

    /**
     * Mass assignment is closed by default; provisioning goes through
     * TenantProvisioningService, which sets attributes explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'registration_number',
        'tax_identification_number',
        'plan_code',
        'country_code',
        'currency_code',
        'timezone',
        'locale',
        'contact_name',
        'contact_email',
        'contact_phone',
        'max_companies',
        'max_users',
    ];

    /**
     * Columns that exist physically. Everything else lands in `data`.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'legal_name',
            'registration_number',
            'tax_identification_number',
            'status',
            'plan_code',
            'trial_ends_at',
            'subscription_ends_at',
            'suspended_at',
            'suspension_reason',
            'provisioned_at',
            'country_code',
            'currency_code',
            'timezone',
            'locale',
            'contact_name',
            'contact_email',
            'contact_phone',
            'max_companies',
            'max_users',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<\Asids\Core\Organization\Domain\Models\Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(\Asids\Core\Organization\Domain\Models\Company::class);
    }

    /**
     * @return HasMany<\Asids\Core\Identity\Domain\Models\User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(\Asids\Core\Identity\Domain\Models\User::class);
    }

    public function primaryDomain(): ?Domain
    {
        return $this->domains->firstWhere('is_primary', true);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Effective ceilings: a contractual override on the tenant wins over the
     * platform default, so an enterprise customer is not silently capped.
     */
    public function companyLimit(): int
    {
        return $this->max_companies ?? (int) config('asids.limits.max_companies_per_tenant');
    }

    public function userLimit(): int
    {
        return $this->max_users ?? (int) config('asids.limits.max_users_per_tenant');
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'data' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'subscription_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'provisioned_at' => 'immutable_datetime',
            'max_companies' => 'integer',
            'max_users' => 'integer',
        ];
    }
}
