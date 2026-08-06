<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Domain\Models;

use Asids\Core\Audit\Domain\Enums\ActorType;
use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable audit entry.
 *
 * The model deliberately offers no way to change one: there is no `$fillable`, `save()` on an
 * existing row is refused by a database trigger, and writes go through AuditRecorder's raw
 * insert. Anything softer would be a shape that looks editable.
 *
 * @property string $id
 * @property int $sequence
 * @property string|null $tenant_id
 * @property AuditEvent $event
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property list<string>|null $changed_attributes
 * @property ActorType $actor_type
 * @property string|null $hash
 * @property string|null $previous_hash
 * @property CarbonImmutable|null $sealed_at
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /**
     * Closed entirely. AuditRecorder writes with an explicit column list; nothing else writes
     * at all.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function isSealed(): bool
    {
        return $this->sealed_at !== null;
    }

    /**
     * The exact bytes the chain hashes.
     *
     * Field order and encoding are part of the integrity guarantee: change either and every
     * historical hash stops verifying. `JSON_UNESCAPED_*` and the fixed key order are what keep
     * the encoding reproducible across PHP versions.
     */
    public function canonicalPayload(): string
    {
        return json_encode([
            'sequence' => $this->sequence,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'event' => $this->event->value,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'actor_type' => $this->actor_type->value,
            'actor_id' => $this->actor_id,
            'impersonator_id' => $this->impersonator_id,
            'created_at' => $this->created_at->toIso8601ZuluString('microsecond'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function computeHash(?string $previousHash): string
    {
        return hash('sha256', ($previousHash ?? '').$this->canonicalPayload());
    }

    public function tenantIsOptional(): bool
    {
        return true;
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeUnsealed(Builder $query): Builder
    {
        return $query->whereNull('sealed_at');
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForRecord(Builder $query, string $type, string $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'actor_type' => ActorType::class,
            'sequence' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_attributes' => 'array',
            'tags' => 'array',
            'sealed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
