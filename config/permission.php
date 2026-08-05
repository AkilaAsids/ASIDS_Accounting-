<?php

declare(strict_types=1);

use Asids\Core\Authorization\Domain\Models\Permission;
use Asids\Core\Authorization\Domain\Models\Role;

/*
|--------------------------------------------------------------------------
| spatie/laravel-permission
|--------------------------------------------------------------------------
|
| "Teams" mode is enabled and the team foreign key is `tenant_id`. This lets
| every tenant define its own roles (an "Accountant" in one tenant is unrelated
| to an "Accountant" in another) while permissions themselves stay global — a
| permission is a capability the software offers, not tenant data.
|
*/

return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_uuid',
        'team_foreign_key' => 'tenant_id',
    ],

    // Roles are scoped per tenant.
    'teams' => true,

    // Roles never carry permissions the underlying user cannot also be granted
    // directly, so the direct-permission path stays authoritative.
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    // Wildcards would make the permission catalogue unauditable; every
    // capability must exist as an explicit row.
    'enable_wildcard_permission' => false,

    'cache' => [
        // Permission lookups happen on nearly every request, so they are cached
        // aggressively and invalidated on write by the package.
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'asids.permission.cache',
        'store' => 'default',
    ],
];
