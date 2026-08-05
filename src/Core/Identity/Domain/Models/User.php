<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Models;

use Asids\Core\Authorization\Domain\Concerns\HasTenantRoles;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who signs in.
 *
 * Tenant scoped, with one exception the schema enforces: ASIDS platform staff have
 * a NULL tenant and `is_platform_admin = true`.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $password
 * @property UserStatus $status
 * @property bool $is_platform_admin
 * @property bool $must_change_password
 * @property string|null $two_factor_secret
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property CarbonImmutable|null $password_changed_at
 * @property CarbonImmutable|null $locked_until
 * @property int $failed_login_attempts
 * @property string|null $default_company_id
 * @property string|null $timezone
 * @property string|null $locale
 * @property string $theme
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use BelongsToTenant;
    use HasApiTokens;
    use HasTenantRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'employee_number',
        'locale',
        'timezone',
        'theme',
        'default_company_id',
    ];

    /**
     * Never serialised, whatever a careless `->toArray()` does.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    // ── Relationships ───────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Company, $this>
     */
    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_company_id');
    }

    /**
     * @return HasMany<CompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class)->whereNull('revoked_at');
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_memberships')
            ->wherePivotNull('revoked_at')
            ->withPivot(['id', 'branch_id', 'is_default', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class)->whereNull('revoked_at');
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistory(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest();
    }

    /**
     * @return HasMany<TwoFactorRecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    /**
     * @return HasMany<PasswordHistory, $this>
     */
    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_id');
    }

    // ── Derived state ───────────────────────────────────────────────────────

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function initials(): string
    {
        $first = mb_substr($this->first_name, 0, 1);
        $last = $this->last_name === null ? '' : mb_substr($this->last_name, 0, 1);

        return mb_strtoupper($first.$last);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether the password has aged past the tenant's rotation policy.
     *
     * A null `password_changed_at` on an active account is treated as expired
     * rather than as "never expires": it means the timestamp was never recorded,
     * and defaulting an unknown to "safe" is how rotation policies quietly stop
     * working.
     */
    public function passwordHasExpired(): bool
    {
        $days = (int) config('asids.auth.password.expires_after_days');

        if ($days <= 0) {
            return false;
        }

        if ($this->must_change_password) {
            return true;
        }

        return $this->password_changed_at === null
            || $this->password_changed_at->addDays($days)->isPast();
    }

    /**
     * Effective preference values, falling back to the tenant then the platform.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone
            ?? $this->tenant?->timezone
            ?? (string) config('asids.regional.default_timezone');
    }

    public function effectiveLocale(): string
    {
        return $this->locale
            ?? $this->tenant?->locale
            ?? (string) config('asids.regional.default_locale');
    }

    /**
     * Company-level data access, which is separate from — and additional to —
     * permissions. Platform staff deliberately have no memberships.
     */
    public function canAccessCompany(string $companyId): bool
    {
        return $this->memberships()
            ->where('company_id', $companyId)
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public function accessibleCompanyIds(): Collection
    {
        /** @var Collection<int, string> $ids */
        $ids = $this->memberships()->pluck('company_id');

        return $ids;
    }

    // ── Tenancy hooks ───────────────────────────────────────────────────────

    /**
     * Platform staff exist outside every tenant.
     */
    public function tenantIsOptional(): bool
    {
        return true;
    }

    // ── Auth plumbing ───────────────────────────────────────────────────────

    /**
     * Sanctum and the session guard both key on this; UUID string is correct.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    // ── Query scopes ────────────────────────────────────────────────────────

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        // Trigram indexes back both predicates (see the users migration), so this
        // stays index-assisted rather than degenerating into a sequential scan on a
        // tenant with thousands of users.
        return $query->where(static function (Builder $inner) use ($term): void {
            $inner->whereRaw("(first_name || ' ' || coalesce(last_name, '')) ILIKE ?", ["%{$term}%"])
                ->orWhereRaw('lower(email) LIKE ?', [strtolower("%{$term}%")]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'password_changed_at' => 'immutable_datetime',
            'must_change_password' => 'boolean',
            'is_platform_admin' => 'boolean',
            // Encrypted at the application layer: a database dump alone must not
            // be enough to mint valid TOTP codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_enrolled_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'invited_at' => 'immutable_datetime',
            'invitation_accepted_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Normalised on the way in so the unique index on lower(email), the login
        // lookup and the invitation link all agree on one canonical form.
        static::saving(static function (self $user): void {
            $user->email = strtolower(trim($user->email));
            $user->first_name = trim($user->first_name);

            if ($user->last_name !== null) {
                $user->last_name = trim($user->last_name);
            }
        });
    }
}
