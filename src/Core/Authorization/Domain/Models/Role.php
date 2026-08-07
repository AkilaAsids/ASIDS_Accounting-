<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Models;

use Asids\Core\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A tenant-defined bundle of capabilities.
 *
 * Tenant scoped, with one exception: a role whose `tenant_id` is NULL is a platform
 * *template*, cloned into a workspace at provisioning. Templates are visible from
 * inside a tenant (hence `tenantScopeIncludesPlatformRows`) so the UI can offer them,
 * but are never assignable directly.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $guard_name
 * @property string $label
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_owner
 * @property int $level
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Role extends SpatieRole
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'roles';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'guard_name',
        'label',
        'description',
        'is_system',
        'is_owner',
        'level',
    ];

    /**
     * A template belongs to the platform, not to a workspace.
     */
    public function isTemplate(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * System roles may have their permissions adjusted but not their identity: renaming
     * or deleting one would break the provisioning contract and any integration that
     * refers to `owner` or `administrator` by name.
     */
    public function isRenameable(): bool
    {
        return ! $this->is_system;
    }

    public function isDeletable(): bool
    {
        return ! $this->is_system && ! $this->is_owner;
    }

    /**
     * The owner role's permissions are granted implicitly by the gate, so editing its
     * pivot rows would be misleading — the UI shows it as "all capabilities" instead.
     */
    public function hasEditablePermissions(): bool
    {
        return ! $this->is_owner;
    }

    // ── Tenancy hooks ───────────────────────────────────────────────────────

    public function tenantIsOptional(): bool
    {
        return true;
    }

    public function tenantScopeIncludesPlatformRows(): bool
    {
        return true;
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /**
     * Roles a tenant may actually assign: its own, never a platform template.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->whereNotNull('tenant_id');
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }

    /**
     * Roles a user of the given level may grant. Strictly below their own, so nobody
     * can clone their own authority or hand out more than they hold.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeGrantableByLevel(Builder $query, int $level): Builder
    {
        return $query->assignable()->where('level', '<', $level);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_owner' => 'boolean',
            'level' => 'integer',
        ];
    }
}
