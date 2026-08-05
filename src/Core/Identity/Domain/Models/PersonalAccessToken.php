<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum token with tenancy, revocation and network restriction.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property array<int, string>|null $allowed_ip_ranges
 */
final class PersonalAccessToken extends SanctumToken
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'personal_access_tokens';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'description',
        'expires_at',
        'allowed_ip_ranges',
    ];

    /** @var list<string> */
    protected $hidden = ['token'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Sanctum checks `expires_at` itself but knows nothing about revocation, so the
     * two are combined here and the guard consults this.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Optional source-address restriction, for integrations that run from a known
     * static address. An empty list means unrestricted.
     */
    public function permitsAddress(?string $ip): bool
    {
        $ranges = $this->allowed_ip_ranges;

        if ($ranges === null || $ranges === [] || $ip === null) {
            return $ranges === null || $ranges === [];
        }

        foreach ($ranges as $range) {
            if ($this->addressMatchesCidr($ip, $range)) {
                return true;
            }
        }

        return false;
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
            'abilities' => 'array',
            'allowed_ip_ranges' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * IPv4 and IPv6 CIDR matching, done on the packed binary form so both families
     * are handled by the same code path.
     */
    private function addressMatchesCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Mixing families (an IPv4 address against an IPv6 range) can never match.
        if (strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int) $bits;
        $maxBits = strlen($ipBinary) * 8;

        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($ipBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
