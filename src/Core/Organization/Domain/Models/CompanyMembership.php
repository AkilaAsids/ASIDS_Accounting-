<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Models;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which users may act inside which companies.
 *
 * Revocation is a timestamp rather than a deletion, so "who had access to these books in
 * March" stays answerable — a question every audit eventually asks.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $user_id
 * @property string|null $branch_id
 * @property bool $is_default
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $revoked_at
 */
final class CompanyMembership extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'company_memberships';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'branch_id',
        'is_default',
        'granted_by_id',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * A NULL `branch_id` means every branch of the company; a value narrows the user to
     * one location, which is how a shop supervisor is kept to their own till.
     */
    public function isBranchRestricted(): bool
    {
        return $this->branch_id !== null;
    }

    /**
     * @param  Builder<CompanyMembership>  $query
     * @return Builder<CompanyMembership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'joined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
