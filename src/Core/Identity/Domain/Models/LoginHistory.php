<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Models;

use Asids\Core\Identity\Domain\Enums\LoginOutcome;
use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authentication attempt.
 *
 * Append-only by convention (`$timestamps` is half-disabled because the table has
 * only `created_at`). Nothing in the application updates a row here; the security
 * value of the table depends on that.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $email_attempted
 * @property LoginOutcome $outcome
 * @property string $ip_address
 * @property CarbonImmutable $created_at
 */
final class LoginHistory extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'login_histories';

    /**
     * The table records the moment of the attempt and nothing else; there is no
     * `updated_at` because an attempt is never modified.
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'device_id',
        'email_attempted',
        'outcome',
        'failure_reason',
        'guard',
        'channel',
        'ip_address',
        'user_agent',
        'device_type',
        'platform',
        'browser',
        'country_code',
        'city',
        'two_factor_used',
        'two_factor_method',
        'session_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'device_id');
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
            'outcome' => LoginOutcome::class,
            'two_factor_used' => 'boolean',
            'created_at' => 'immutable_datetime',
            'logged_out_at' => 'immutable_datetime',
        ];
    }
}
