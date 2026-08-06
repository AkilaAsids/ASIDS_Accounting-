<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Notifications;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset link.
 *
 * Queued on `critical` because a user is staring at their inbox waiting for it — an ordinary
 * queue behind a month-end batch would make the reset appear broken.
 */
final class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url)
    {
        $this->onQueue('critical');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $minutes = 60;

        return (new MailMessage())
            ->subject('Reset your '.config('app.name').' password')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('We received a request to reset the password for your '.($notifiable->tenant?->name ?? config('app.name')).' account.')
            ->action('Choose a new password', $this->url)
            ->line("This link expires in {$minutes} minutes and can only be used once.")
            // Told plainly, because the alternative is a support ticket from someone who did not
            // request it and does not know whether to worry.
            ->line('If you did not request this, no action is needed — your current password still works. If you receive these repeatedly, contact your administrator.')
            ->salutation('— The '.config('app.name').' team');
    }
}
