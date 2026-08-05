<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Providers;

use Asids\Core\Platform\Domain\Contracts\CompliancePackContract;
use Asids\Core\Platform\Support\NullCompliancePack;
use Asids\Core\Platform\Support\RequestContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Cross-cutting platform concerns that belong to no single bounded context.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One request context object per request, shared by the audit trail, the
        // logger and the exception renderer so all three agree on the request id.
        $this->app->scoped(RequestContext::class, static fn (): RequestContext => new RequestContext());

        $this->app->bind(CompliancePackContract::class, function (): CompliancePackContract {
            $country = (string) config('asids.regional.default_country', 'LK');
            /** @var array<string, class-string<CompliancePackContract>> $packs */
            $packs = config('asids.regional.compliance_packs', []);

            /** @var class-string<CompliancePackContract> $pack */
            $pack = $packs[$country] ?? NullCompliancePack::class;

            return $this->app->make($pack);
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureMorphMap();
        $this->configurePasswordDefaults();
        $this->configureQueryMonitoring();
    }

    /**
     * Strictness that turns a class of silent production bug into a loud
     * development failure.
     */
    private function configureModels(): void
    {
        // Accessing an attribute that was not selected, or a relation that was
        // not eager loaded, throws outside production. This is how the platform
        // keeps N+1 queries out of a codebase that will hold hundreds of tables.
        Model::shouldBeStrict(! $this->app->isProduction());

        // In production, prefer a missing attribute over an exception in a
        // customer's face, but still refuse to lazy load: an unbounded query
        // storm is worse than a missing field on a dashboard.
        if ($this->app->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                Log::warning('Lazy loading violation.', [
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            });
        }
    }

    /**
     * An explicit morph map keeps polymorphic columns readable and, critically,
     * decouples stored data from PHP class names so a namespace refactor cannot
     * orphan every audit entry.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => \Asids\Core\Identity\Domain\Models\User::class,
            'access_token' => \Asids\Core\Identity\Domain\Models\PersonalAccessToken::class,
            'user_device' => \Asids\Core\Identity\Domain\Models\UserDevice::class,
            'tenant' => \Asids\Core\Tenancy\Domain\Models\Tenant::class,
            'company' => \Asids\Core\Organization\Domain\Models\Company::class,
            'branch' => \Asids\Core\Organization\Domain\Models\Branch::class,
            'company_membership' => \Asids\Core\Organization\Domain\Models\CompanyMembership::class,
            'role' => \Asids\Core\Authorization\Domain\Models\Role::class,
            'permission' => \Asids\Core\Authorization\Domain\Models\Permission::class,
            'setting' => \Asids\Core\Settings\Domain\Models\Setting::class,
        ]);
    }

    /**
     * The password policy is defined once, here, so that registration,
     * invitation acceptance, self-service change and administrative reset cannot
     * drift apart.
     */
    private function configurePasswordDefaults(): void
    {
        Password::defaults(static function (): Password {
            /** @var array{min_length:int, require_mixed_case:bool, require_numbers:bool, require_symbols:bool, check_compromised:bool} $policy */
            $policy = config('asids.auth.password');

            $rule = Password::min($policy['min_length']);

            if ($policy['require_mixed_case']) {
                $rule = $rule->mixedCase();
            }

            if ($policy['require_numbers']) {
                $rule = $rule->numbers();
            }

            if ($policy['require_symbols']) {
                $rule = $rule->symbols();
            }

            // Breach checking calls out to the k-anonymity Pwned Passwords range
            // API, so it is skipped in tests where it would be a flaky network
            // dependency rather than a security control.
            if ($policy['check_compromised'] && ! app()->runningUnitTests()) {
                $rule = $rule->uncompromised();
            }

            return $rule;
        });
    }

    /**
     * Slow and excessive queries are surfaced with the request id attached, which
     * is what makes a "the dashboard is slow" report actionable.
     */
    private function configureQueryMonitoring(): void
    {
        if ($this->app->isProduction()) {
            DB::whenQueryingForLongerThan(
                threshold: 2_000,
                handler: static function (Connection $connection): void {
                    Log::warning('Cumulative query time exceeded threshold.', [
                        'connection' => $connection->getName(),
                        'request_id' => app(RequestContext::class)->requestId(),
                    ]);
                }
            );

            return;
        }

        DB::listen(static function (QueryExecuted $query): void {
            if ($query->time < 500) {
                return;
            }

            Log::debug('Slow query.', [
                'sql' => Str::limit($query->sql, 500),
                'time_ms' => $query->time,
            ]);
        });
    }
}
