<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Models;

use Asids\Core\Authorization\Domain\Catalogue\PermissionCatalogue;
use Asids\Core\Authorization\Domain\Catalogue\PermissionDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A capability the software offers.
 *
 * Global, not tenant scoped — a permission is a property of the product, not of a
 * customer. Rows are synchronised from PermissionCatalogue and are never editable
 * through the API.
 *
 * @property string $id
 * @property string $name
 * @property string $module
 * @property string $resource
 * @property string $action
 * @property string $label
 * @property bool $is_sensitive
 */
final class Permission extends SpatiePermission
{
    use HasUuids;

    protected $table = 'permissions';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'guard_name',
        'module',
        'resource',
        'action',
        'label',
        'description',
        'is_sensitive',
        'sort_order',
    ];

    public function definition(): ?PermissionDefinition
    {
        return PermissionCatalogue::find($this->name);
    }

    /**
     * Platform staff capabilities are identified by module rather than by a column, so
     * the catalogue stays the single source of truth for the distinction.
     */
    public function isPlatformOnly(): bool
    {
        return $this->module === 'platform';
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeGrantableToTenants(Builder $query): Builder
    {
        return $query->where('module', '!=', 'platform');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
