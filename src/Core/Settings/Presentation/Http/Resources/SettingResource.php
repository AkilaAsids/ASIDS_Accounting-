<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Presentation\Http\Resources;

use Asids\Core\Settings\Domain\Catalogue\SettingDefinition;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A setting's definition together with its resolved value.
 *
 * The definition travels with the value so the settings screen is entirely server-driven: adding a
 * setting to the catalogue makes it appear in the interface with the right control, label and help
 * text, without a front-end change. That is what keeps a hundred-plus settings maintainable.
 *
 * @property-read SettingDefinition $resource
 */
final class SettingResource extends JsonResource
{
    public function __construct(
        SettingDefinition $definition,
        private readonly mixed $resolvedValue,
        private readonly bool $isOverridden,
        private readonly ?SettingScope $overriddenAt = null,
    ) {
        parent::__construct($definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->key,
            'label' => $this->resource->label,
            'description' => $this->resource->description,
            'group' => $this->resource->group,
            'type' => $this->resource->type->value,

            'value' => $this->resolvedValue,
            'default' => $this->resource->default,

            // Lets the UI show "inherited from workspace" and offer a reset — without it a user
            // cannot tell a deliberate choice from an inherited default.
            'is_overridden' => $this->isOverridden,
            'overridden_at' => $this->overriddenAt?->value,

            'options' => $this->resource->options,
            'overridable_at' => array_map(
                static fn (SettingScope $scope): string => $scope->value,
                $this->resource->overridableAt,
            ),
            'sort_order' => $this->resource->sortOrder,
        ];
    }
}
