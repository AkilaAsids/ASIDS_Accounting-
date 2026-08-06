<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Presentation\Http\Controllers;

use Asids\Core\Settings\Application\Services\SettingsResolver;
use Asids\Core\Settings\Application\Services\SettingsService;
use Asids\Core\Settings\Domain\Catalogue\SettingDefinition;
use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Asids\Core\Settings\Presentation\Http\Requests\UpdateSettingsRequest;
use Asids\Core\Settings\Presentation\Http\Resources\SettingResource;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettingsController extends ApiController
{
    public function __construct(
        private readonly SettingsResolver $resolver,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Every setting visible at a scope, with its resolved value, grouped for the settings screen.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = SettingScope::tryFrom((string) $request->query('scope', 'tenant'));

        if ($scope === null) {
            throw BusinessRuleViolation::make(
                code: 'unknown-setting-scope',
                message: 'That settings scope does not exist.',
            );
        }

        $this->authorize('viewAtScope', [Setting::class, $scope]);

        $user = $this->currentUser();

        // A user may only ever read their *own* personal settings, whatever permissions they hold:
        // another person's notification preferences are not administrative data.
        $scopeId = match ($scope) {
            SettingScope::User => (string) $user->getKey(),
            SettingScope::Company => $this->resolveCompanyId($request),
            default => null,
        };

        $grouped = [];

        foreach (SettingsCatalogue::all() as $definition) {
            if (! $definition->isOverridableAt($scope)) {
                continue;
            }

            $grouped[$definition->group][] = new SettingResource(
                definition: $definition,
                resolvedValue: $this->resolver->get(
                    key: $definition->key,
                    userId: $scope === SettingScope::User ? $scopeId : (string) $user->getKey(),
                    companyId: $scope === SettingScope::Company ? $scopeId : null,
                ),
                isOverridden: $this->isOverridden($definition, $scope, $scopeId),
                overriddenAt: $scope,
            );
        }

        return ApiResponse::item(
            data: array_map(
                static fn (array $items, string $group): array => [
                    'group' => $group,
                    'settings' => array_map(
                        static fn (SettingResource $r): array => $r->resolve(request()),
                        $items,
                    ),
                ],
                $grouped,
                array_keys($grouped),
            ),
            meta: ['scope' => $scope->value, 'scope_id' => $scopeId],
        );
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $values */
        $values = $request->validated('settings');

        $resolved = $this->settings->setMany(
            values: $values,
            scope: $request->scopeEnum(),
            scopeId: $request->scopeTarget(),
            actor: $this->currentUser(),
        );

        return ApiResponse::item(
            data: $resolved,
            meta: ['scope' => $request->scopeEnum()->value, 'updated' => count($resolved)],
        );
    }

    /**
     * Remove an override so the value inherits again.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $scope = SettingScope::tryFrom((string) $request->input('scope', 'tenant'));

        if ($scope === null) {
            throw BusinessRuleViolation::make(
                code: 'unknown-setting-scope',
                message: 'That settings scope does not exist.',
            );
        }

        $this->authorize('updateAtScope', [Setting::class, $scope]);

        $scopeId = $scope === SettingScope::User
            ? (string) $this->currentUser()->getKey()
            : ($scope === SettingScope::Company ? $this->resolveCompanyId($request) : null);

        $this->settings->reset($key, $scope, $scopeId);

        return ApiResponse::item([
            'key' => $key,
            'value' => $this->resolver->get($key, (string) $this->currentUser()->getKey(), $scopeId),
            'is_overridden' => false,
        ]);
    }

    /**
     * The settings the interface needs before it can render anything — locale formats and branding.
     *
     * Unauthorised beyond being signed in, because withholding the date format from a user who
     * lacks the settings permission would leave them looking at raw timestamps.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::item($this->resolver->publicSettings(
            userId: (string) $user->getKey(),
            companyId: $user->default_company_id,
        ));
    }

    private function isOverridden(SettingDefinition $definition, SettingScope $scope, ?string $scopeId): bool
    {
        return Setting::query()
            ->withoutGlobalScopes()
            ->when(
                $scope === SettingScope::System,
                static fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $this->currentUser()->tenant_id),
            )
            ->atScope($scope, $scopeId)
            ->where('key', $definition->key)
            ->exists();
    }

    private function resolveCompanyId(Request $request): string
    {
        $company = $request->attributes->get(
            \Asids\Core\Organization\Presentation\Http\Middleware\ResolveActiveCompany::ATTRIBUTE
        );

        if ($company instanceof \Asids\Core\Organization\Domain\Models\Company) {
            return (string) $company->getKey();
        }

        throw BusinessRuleViolation::make(
            code: 'company-not-selected',
            message: 'A company must be selected to read or change company settings.',
        );
    }
}
