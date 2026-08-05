<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use two factor recovery code.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 * @property string|null $used_ip
 */
final class TwoFactorRecoveryCode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'two_factor_recovery_codes';

    /** @var list<string> */
    protected $fillable = ['user_id', 'code_hash'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Recovery codes are high-entropy random strings, so a single SHA-256 is the
     * right primitive: there is nothing to brute force, and a slow KDF would make
     * checking eight candidates needlessly expensive on every recovery attempt.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', self::normalise($plaintext));
    }

    /**
     * Users retype codes with the display hyphen and in mixed case; accept both
     * rather than rejecting a correct code on presentation grounds.
     */
    public static function normalise(string $plaintext): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $plaintext) ?? '');
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
        return ['used_at' => 'immutable_datetime'];
    }
}
