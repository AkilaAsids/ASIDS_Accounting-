<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's financial year.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $label
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property bool $is_closed
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read EloquentCollection<int, FiscalPeriod> $periods
 */
final class FiscalYear extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'fiscal_years';

    /** @var list<string> */
    protected $fillable = ['label', 'starts_on', 'ends_on'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<FiscalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function range(): DateRange
    {
        return DateRange::between($this->starts_on, $this->ends_on);
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $this->range()->contains($date);
    }

    /**
     * @param  Builder<FiscalYear>  $query
     * @return Builder<FiscalYear>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<FiscalYear>  $query
     * @return Builder<FiscalYear>
     */
    public function scopeContaining(Builder $query, CarbonImmutable $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_closed' => 'boolean',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
