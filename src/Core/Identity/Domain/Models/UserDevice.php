<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device a user has signed in from.
 *
 * @property string $id
 * @property string $user_id
 * @property string $fingerprint_hash
 * @property string $name
 * @property CarbonImmutable|null $trusted_at
 * @property CarbonImmutable|null $trust_expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_seen_at
 */
final class UserDevice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'user_devices';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'fingerprint_hash',
        'name',
        'device_type',
        'platform',
        'browser',
        'last_ip_address',
        'last_country_code',
        'last_seen_at',
    ];

    /** @var list<string> */
    protected $hidden = ['fingerprint_hash'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A trusted device may skip the two factor challenge until its trust expires.
     * Trust is time-boxed on purpose: a device that was trusted eighteen months ago
     * on a since-sold laptop should not still be trusted.
     */
    public function isTrusted(): bool
    {
        return $this->revoked_at === null
            && $this->trusted_at !== null
            && ($this->trust_expires_at === null || $this->trust_expires_at->isFuture());
    }

    public function tenantIsOptional(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trusted_at' => 'immutable_datetime',
            'trust_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
