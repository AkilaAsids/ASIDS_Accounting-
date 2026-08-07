<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Providers;

use Asids\Core\Identity\Domain\Contracts\UserRepositoryContract;
use Asids\Core\Identity\Domain\Events\PasswordChanged;
use Asids\Core\Identity\Domain\Events\UserInvited;
use Asids\Core\Identity\Domain\Models\PersonalAccessToken;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Infrastructure\Repositories\EloquentUserRepository;
use Asids\Core\Identity\Policies\AccessTokenPolicy;
use Asids\Core\Identity\Policies\UserPolicy;
use Asids\Core\Identity\Presentation\Console\RevokeExpiredTokensCommand;
use Asids\Core\Identity\Presentation\Notifications\InvitationLink;
use Asids\Core\Identity\Presentation\Notifications\PasswordChangedAlert;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryContract::class, EloquentUserRepository::class);

        $this->app->singleton(Google2FA::class);

        // AuthenticationService depends on the *stateful* guard contract, because it regenerates
        // the session and issues remember tokens. Binding it explicitly keeps that requirement
        // visible rather than resolving whatever `auth()` happens to return.
        $this->app->bind(StatefulGuard::class, static function (): StatefulGuard {
            $guard = auth()->guard('web');

            // `guard()` is typed as returning the base contract, and a `web` guard reconfigured
            // to a stateless driver would satisfy it while breaking session regeneration. Failing
            // here names the misconfiguration; failing later would surface as a sign-in that
            // appears to succeed and leaves no session.
            if (! $guard instanceof StatefulGuard) {
                throw new RuntimeException(
                    'The `web` guard must be stateful: AuthenticationService regenerates the '
                    .'session and issues remember tokens through it.'
                );
            }

            return $guard;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Subclassed to add tenancy, revocation and network restriction; Sanctum must be told, or
        // it resolves its own model and the extra columns go unread.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(PersonalAccessToken::class, AccessTokenPolicy::class);

        $this->registerNotificationListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([RevokeExpiredTokensCommand::class]);
        }
    }

    /**
     * Notifications are wired to domain events rather than sent inline from services, so that a
     * mail failure can never roll back a successful state transition — and so a test can assert
     * the event without a mail fake.
     */
    private function registerNotificationListeners(): void
    {
        Event::listen(UserInvited::class, static function (UserInvited $event): void {
            $event->user->notify(new InvitationLink(
                url: $event->invitationUrl,
                invitedByName: $event->invitedBy->fullName(),
            ));
        });

        Event::listen(PasswordChanged::class, static function (PasswordChanged $event): void {
            // `reset-requested` fires when a link is *issued*, not when a password actually
            // changes; alerting then would tell the user their password had changed when it had
            // not, and train them to ignore the alert that matters.
            if ($event->initiatedBy === 'reset-requested') {
                return;
            }

            $event->user->notify(new PasswordChangedAlert($event->initiatedBy));
        });
    }
}
