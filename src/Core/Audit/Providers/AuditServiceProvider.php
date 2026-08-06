<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Providers;

use Asids\Core\Audit\Application\Services\ActivityLogger;
use Asids\Core\Audit\Application\Services\AuditRecorder;
use Asids\Core\Audit\Domain\Enums\AuditEvent;
use Asids\Core\Audit\Domain\Models\ActivityLog;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Audit\Policies\ActivityLogPolicy;
use Asids\Core\Audit\Policies\AuditLogPolicy;
use Asids\Core\Audit\Presentation\Console\PruneAuditLogCommand;
use Asids\Core\Audit\Presentation\Console\SealAuditChainCommand;
use Asids\Core\Audit\Presentation\Console\VerifyAuditChainCommand;
use Asids\Core\Authorization\Domain\Events\RoleAssignmentChanged;
use Asids\Core\Authorization\Domain\Events\RolePermissionsChanged;
use Asids\Core\Identity\Domain\Events\PasswordChanged;
use Asids\Core\Identity\Domain\Events\TwoFactorDisabled;
use Asids\Core\Identity\Domain\Events\TwoFactorEnabled;
use Asids\Core\Identity\Domain\Events\UserDeactivated;
use Asids\Core\Identity\Domain\Events\UserSuspended;
use Asids\Core\Organization\Domain\Events\CompanyArchived;
use Asids\Core\Organization\Domain\Events\CompanyCreated;
use Asids\Core\Organization\Domain\Events\MembershipGranted;
use Asids\Core\Organization\Domain\Events\MembershipRevoked;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditRecorder::class);
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        $this->recordSecurityEvents();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SealAuditChainCommand::class,
                VerifyAuditChainCommand::class,
                PruneAuditLogCommand::class,
            ]);
        }
    }

    /**
     * Audits the changes that model observers cannot see.
     *
     * The `Auditable` observer covers attribute changes on a row. It cannot see anything that
     * happens in a pivot table or in a service method — and privilege changes, which are the
     * highest-value entries in the whole trail, are exactly that. `model_has_roles` has no model,
     * so granting someone the administrator role would otherwise leave no audit entry at all.
     *
     * Listening here rather than calling the recorder from inside each service keeps the audit
     * concern out of the services and makes the full set of audited security events readable in
     * one place.
     */
    private function recordSecurityEvents(): void
    {
        $recorder = fn (): AuditRecorder => $this->app->make(AuditRecorder::class);
        $activity = fn (): ActivityLogger => $this->app->make(ActivityLogger::class);

        Event::listen(RoleAssignmentChanged::class, function (RoleAssignmentChanged $event) use ($recorder, $activity): void {
            $recorder()->record(
                subject: $event->user,
                event: AuditEvent::PermissionChanged,
                oldValues: ['roles' => $event->previousRoles],
                newValues: ['roles' => $event->currentRoles],
                tags: ['security', 'privilege'],
                // Self-assignment should be impossible (the policy refuses it), so an entry
                // marked this way is evidence of a bypass and worth flagging in the trail itself.
                reason: $event->isSelfAssignment() ? 'Self-assignment' : null,
            );

            $activity()->log(
                description: sprintf(
                    '%s changed roles for %s',
                    $event->actor->fullName(),
                    $event->user->fullName(),
                ),
                subject: $event->user,
                channel: 'security',
                event: 'roles.changed',
                properties: ['gained' => $event->gained(), 'lost' => $event->lost()],
            );
        });

        Event::listen(RolePermissionsChanged::class, function (RolePermissionsChanged $event) use ($recorder): void {
            $recorder()->record(
                subject: $event->role,
                event: AuditEvent::PermissionChanged,
                oldValues: ['permissions' => $event->previousPermissions],
                newValues: ['permissions' => $event->currentPermissions],
                tags: ['security', 'privilege'],
            );
        });

        Event::listen(TwoFactorEnabled::class, function (TwoFactorEnabled $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->user,
                event: AuditEvent::Updated,
                properties: ['two_factor_enabled' => true],
                tags: ['security'],
            );
        });

        Event::listen(TwoFactorDisabled::class, function (TwoFactorDisabled $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->user,
                event: AuditEvent::Updated,
                properties: ['two_factor_enabled' => false],
                tags: ['security'],
                // Someone removing another person's second factor is the case worth alerting on,
                // so the trail records which of the two happened.
                reason: $event->disabledBy->getKey() === $event->user->getKey()
                    ? 'Disabled by the account holder'
                    : 'Cleared by '.$event->disabledBy->fullName(),
            );
        });

        Event::listen(PasswordChanged::class, function (PasswordChanged $event) use ($recorder): void {
            if ($event->initiatedBy === 'reset-requested') {
                return;
            }

            $recorder()->recordAction(
                subject: $event->user,
                event: AuditEvent::Updated,
                properties: ['password_changed' => true, 'initiated_by' => $event->initiatedBy],
                tags: ['security', 'credential'],
            );
        });

        Event::listen(UserSuspended::class, function (UserSuspended $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->user,
                event: AuditEvent::Updated,
                properties: ['status' => 'suspended'],
                tags: ['security'],
                reason: $event->reason,
            );
        });

        Event::listen(UserDeactivated::class, function (UserDeactivated $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->user,
                event: AuditEvent::Updated,
                properties: ['status' => 'deactivated'],
                tags: ['security'],
                reason: $event->reason,
            );
        });

        // Company access is a data boundary, so granting it belongs alongside role changes in the
        // trail — together they answer "who could have entered this transaction?".
        Event::listen(MembershipGranted::class, function (MembershipGranted $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->membership,
                event: AuditEvent::Created,
                properties: [
                    'company_id' => $event->membership->company_id,
                    'user_id' => $event->membership->user_id,
                    'branch_id' => $event->membership->branch_id,
                ],
                tags: ['security', 'access'],
            );
        });

        Event::listen(MembershipRevoked::class, function (MembershipRevoked $event) use ($recorder): void {
            $recorder()->recordAction(
                subject: $event->membership,
                event: AuditEvent::Deleted,
                properties: [
                    'company_id' => $event->membership->company_id,
                    'user_id' => $event->membership->user_id,
                ],
                tags: ['security', 'access'],
            );
        });

        Event::listen(CompanyCreated::class, function (CompanyCreated $event) use ($activity): void {
            $activity()->log(
                description: sprintf('%s created the company %s', $event->createdBy->fullName(), $event->company->name),
                subject: $event->company,
                channel: 'organization',
                event: 'company.created',
            );
        });

        Event::listen(CompanyArchived::class, function (CompanyArchived $event) use ($activity): void {
            $activity()->log(
                description: sprintf('%s archived the company %s', $event->archivedBy->fullName(), $event->company->name),
                subject: $event->company,
                channel: 'organization',
                event: 'company.archived',
            );
        });
    }
}
