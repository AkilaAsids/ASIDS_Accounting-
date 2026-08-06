<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Application\DTOs;

use Illuminate\Support\Str;

/**
 * Input for creating or updating a customer-defined role.
 */
final readonly class RoleData
{
    /**
     * @param  list<string>  $permissionNames
     */
    public function __construct(
        public string $label,
        public ?string $description,
        public array $permissionNames,
        public ?int $level = null,
        /**
         * The machine name. Derived from the label on create and immutable thereafter,
         * because integrations and the audit trail refer to a role by name.
         */
        public ?string $name = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_unique(array_filter(
            (array) ($input['permissions'] ?? []),
            'is_string',
        )));

        $label = trim((string) ($input['label'] ?? ''));

        return new self(
            label: $label,
            description: self::nullableString($input['description'] ?? null),
            permissionNames: $permissions,
            level: isset($input['level']) ? (int) $input['level'] : null,
            name: self::nullableString($input['name'] ?? null) ?? Str::slug($label, '_'),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
