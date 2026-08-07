<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\DTOs;

use Asids\Core\Accounting\Domain\Enums\AccountType;

/**
 * Input for creating an account.
 *
 * `normalBalance` is deliberately absent: it is a function of the type, and offering it as an input
 * would let a caller create an account whose sign convention disagrees with its classification —
 * which reports every figure backwards while the books still balance.
 */
final readonly class CreateAccountData
{
    /**
     * @param  string|null  $systemKey  Set only by the platform, never from a request.
     */
    public function __construct(
        public string $code,
        public string $name,
        public AccountType $type,
        public ?string $parentId = null,
        public ?string $description = null,
        public bool $isPostable = true,
        public int $sortOrder = 0,
        public ?string $systemKey = null,
        public ?string $templateVersion = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            code: (string) $attributes['code'],
            name: (string) $attributes['name'],
            type: $attributes['type'] instanceof AccountType
                ? $attributes['type']
                : AccountType::from((string) $attributes['type']),
            parentId: isset($attributes['parent_id']) ? (string) $attributes['parent_id'] : null,
            description: isset($attributes['description']) ? (string) $attributes['description'] : null,
            isPostable: (bool) ($attributes['is_postable'] ?? true),
            sortOrder: (int) ($attributes['sort_order'] ?? 0),
        );
    }
}
