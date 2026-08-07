<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Accounting\Domain\Enums\PeriodStatus;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A division of a fiscal year that postings are dated into.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $fiscal_year_id
 * @property int $sequence
 * @property string $label
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property PeriodStatus $status
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 * @property-read FiscalYear $fiscalYear
 */
final class FiscalPeriod extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'fiscal_periods';

    /** @var list<string> */
    protected $fillable = ['sequence', 'label', 'starts_on', 'ends_on'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
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
     * Whether an entry dated in this period may be posted right now.
     *
     * Asked of the period rather than of its status directly, so a caller cannot accidentally check
     * only the enum and miss a rule that later depends on the year as well.
     */
    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }

    /**
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * The period a date falls into. At most one, guaranteed by the exclusion constraint.
     *
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeContaining(Builder $query, CarbonImmutable $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }

    /**
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PeriodStatus::Open->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => PeriodStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }
}
