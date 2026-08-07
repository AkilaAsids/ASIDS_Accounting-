<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Models;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A legal entity that keeps its own books.
 *
 * Two attributes are immutable once the company has ledger activity —
 * `base_currency_code` and the fiscal year start — because changing either would
 * silently reinterpret every historical balance rather than converting it. The
 * enforcement lives in CompanyService, which consults the ledger-activity probe; this
 * model exposes the question but does not answer it, since the accounting tables it
 * would have to inspect belong to a later phase.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $legal_name
 * @property string $code
 * @property string $slug
 * @property string|null $registration_number
 * @property string|null $tax_identification_number
 * @property string|null $vat_registration_number
 * @property string|null $svat_registration_number
 * @property string|null $business_type
 * @property string|null $industry
 * @property CarbonImmutable|null $established_on
 * @property string $base_currency_code
 * @property int $fiscal_year_start_month
 * @property int $fiscal_year_start_day
 * @property int $currency_precision
 * @property string $country_code
 * @property string $timezone
 * @property string $locale
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $district
 * @property string|null $postal_code
 * @property string|null $logo_path
 * @property bool $is_vat_registered
 * @property bool $is_svat_registered
 * @property OrganizationStatus $status
 * @property bool $is_default
 * @property CarbonImmutable|null $archived_at
 * @property string|null $created_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Company extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'companies';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'legal_name',
        'code',
        'slug',
        'registration_number',
        'tax_identification_number',
        'vat_registration_number',
        'svat_registration_number',
        'is_vat_registered',
        'is_svat_registered',
        'business_type',
        'industry',
        'established_on',
        'base_currency_code',
        'fiscal_year_start_month',
        'fiscal_year_start_day',
        'currency_precision',
        'country_code',
        'timezone',
        'locale',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'district',
        'postal_code',
        'logo_path',
    ];

    // ── Relationships ───────────────────────────────────────────────────────

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function activeBranches(): HasMany
    {
        return $this->branches()->where('status', OrganizationStatus::Active->value);
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class)->whereNull('revoked_at');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_memberships')
            ->wherePivotNull('revoked_at')
            ->withPivot(['id', 'branch_id', 'is_default', 'joined_at']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ── Derived state ───────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * The branch transactions land on when a document does not name one. Guaranteed to
     * exist by CompanyService, and by a partial unique index that permits exactly one
     * per company.
     */
    public function primaryBranch(): ?Branch
    {
        if ($this->relationLoaded('branches')) {
            return $this->branches->firstWhere('is_primary', true);
        }

        return $this->branches()->where('is_primary', true)->first();
    }

    public function displayName(): string
    {
        return $this->legal_name ?? $this->name;
    }

    /**
     * First day of the fiscal year containing the given date.
     *
     * Sri Lanka's assessment year starts in April, so this cannot assume January, and
     * the fiscal year is named by the calendar year in which it *begins*.
     */
    public function fiscalYearStartFor(CarbonImmutable $date): CarbonImmutable
    {
        $candidate = $date
            ->setMonth($this->fiscal_year_start_month)
            ->setDay($this->fiscal_year_start_day)
            ->startOfDay();

        // A date before this year's boundary belongs to the fiscal year that opened in
        // the previous calendar year.
        return $candidate->greaterThan($date)
            ? $candidate->subYear()
            : $candidate;
    }

    public function fiscalYearEndFor(CarbonImmutable $date): CarbonImmutable
    {
        return $this->fiscalYearStartFor($date)->addYear()->subDay()->endOfDay();
    }

    /**
     * Whether the fiscal year matches the calendar year, which decides whether reports
     * can be labelled "2026" or must be labelled "2026/27".
     */
    public function usesCalendarFiscalYear(): bool
    {
        return $this->fiscal_year_start_month === 1 && $this->fiscal_year_start_day === 1;
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrganizationStatus::Active->value);
    }

    /**
     * Companies a given user may actually see. Applied in addition to the tenant scope:
     * membership is a data boundary that permissions do not express.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        // Platform staff hold no memberships and must not gain access to customer books
        // by virtue of being staff; reading a customer's data goes through the audited
        // impersonation flow instead.
        return $query->whereExists(
            static fn ($sub) => $sub
                ->from('company_memberships')
                ->whereColumn('company_memberships.company_id', 'companies.id')
                ->where('company_memberships.user_id', $user->getKey())
                ->whereNull('company_memberships.revoked_at')
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'is_default' => 'boolean',
            'is_vat_registered' => 'boolean',
            'is_svat_registered' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'fiscal_year_start_day' => 'integer',
            'currency_precision' => 'integer',
            'established_on' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $company): void {
            // The database enforces uniqueness on `upper(code)` and `lower(slug)`, so the
            // stored form is normalised here rather than leaving two representations of
            // the same value in the table.
            $company->code = strtoupper(trim($company->code));
            $company->slug = strtolower(trim($company->slug));
            $company->base_currency_code = strtoupper(trim($company->base_currency_code));
            $company->country_code = strtoupper(trim($company->country_code));
        });
    }
}
