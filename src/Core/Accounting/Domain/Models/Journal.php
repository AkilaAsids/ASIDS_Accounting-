<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\Models;

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book entries are recorded in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_general
 * @property bool $is_system
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Company $company
 */
final class Journal extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** The general journal's code. One per company, resolved by this rather than by name. */
    public const string GENERAL = 'GJ';

    protected $table = 'journals';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<Journal>  $query
     * @return Builder<Journal>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<Journal>  $query
     * @return Builder<Journal>
     */
    public function scopeGeneral(Builder $query): Builder
    {
        return $query->where('is_general', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_general' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
