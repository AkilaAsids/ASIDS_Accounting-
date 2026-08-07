<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A sentence a business user understands: "Nimal approved invoice INV-0042".
 *
 * Product feature, not compliance record — see the migration comment for why the two are
 * separate tables.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $log_name
 * @property string|null $event
 * @property string $description
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property string|null $causer_type
 * @property string|null $causer_id
 * @property string|null $causer_label
 * @property array<string, mixed>|null $properties
 * @property string|null $batch_id
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ActivityLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'activity_logs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'log_name',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'subject_label',
        'causer_type',
        'causer_id',
        'causer_label',
        'properties',
        'batch_id',
        'request_id',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenantIsOptional(): bool
    {
        return true;
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeInChannel(Builder $query, string $logName): Builder
    {
        return $query->where('log_name', $logName);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
