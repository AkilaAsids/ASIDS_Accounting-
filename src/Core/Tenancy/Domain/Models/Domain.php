<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A hostname that resolves to a tenant.
 *
 * Subclassed from stancl/tenancy's model for two reasons: the platform is UUID
 * keyed throughout, and ASIDS supports customer-owned hostnames, which need a
 * verification state the base model does not model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $domain
 * @property bool $is_primary
 * @property bool $is_custom
 * @property CarbonImmutable|null $verified_at
 */
final class Domain extends BaseDomain
{
    use HasUuids;

    protected $table = 'domains';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'domain',
        'is_primary',
        'is_custom',
        'verification_token',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * A hostname routes traffic only once it is trusted: platform-issued
     * subdomains are trusted on creation, customer-supplied ones only after DNS
     * verification. Without this check a customer could claim a hostname they do
     * not control and receive sessions intended for it.
     */
    public function isUsable(): bool
    {
        return ! $this->is_custom || $this->verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_custom' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Hostnames are case-insensitive; normalise on the way in so the unique
        // index on lower(domain) and every lookup agree.
        static::saving(static function (self $domain): void {
            $domain->domain = strtolower(trim($domain->domain));
        });
    }
}
