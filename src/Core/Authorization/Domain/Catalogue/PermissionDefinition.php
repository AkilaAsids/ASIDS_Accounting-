<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Catalogue;

/**
 * One capability the software offers.
 *
 * Permissions are defined in code, not in the database, and the database is
 * synchronised from this catalogue. That direction matters: a permission is a branch
 * in a policy, so one that exists as a row without corresponding code is a lie, and
 * one that exists in code without a row silently denies everyone. Making code the
 * source of truth means a capability arrives and departs with the feature that
 * implements it.
 */
final readonly class PermissionDefinition
{
    public function __construct(
        public string $module,
        public string $resource,
        public string $action,
        public string $label,
        public string $description,
        /**
         * Capabilities that can move money, alter posted history, or weaken another
         * user's security. Flagged so the UI can demand step-up authentication and so
         * the role seeder refuses to put them in a low-privilege template by accident.
         */
        public bool $sensitive = false,
        /**
         * Platform-staff-only capabilities. Never granted to a tenant role, and
         * satisfied by the `platform.*` short circuit in AuthServiceProvider.
         */
        public bool $platformOnly = false,
        public int $sortOrder = 0,
    ) {}

    /**
     * The canonical permission name. The `permissions` table has a check constraint
     * asserting exactly this composition, so the two can never drift.
     */
    public function name(): string
    {
        return "{$this->module}.{$this->resource}.{$this->action}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'name' => $this->name(),
            'guard_name' => 'web',
            'module' => $this->module,
            'resource' => $this->resource,
            'action' => $this->action,
            'label' => $this->label,
            'description' => $this->description,
            'is_sensitive' => $this->sensitive,
            'sort_order' => $this->sortOrder,
        ];
    }
}
