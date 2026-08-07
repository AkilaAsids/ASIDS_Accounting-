<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Notifications;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Workspace invitation.
 *
 * Names the inviter and the workspace: an unexplained "set your password" mail from a product the
 * recipient has never heard of reads exactly like phishing, and gets deleted.
 */
final class InvitationLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $url,
        private readonly string $invitedByName,
    ) {
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
        $workspace = $notifiable->tenant->name ?? config('app.name');

        return (new MailMessage)
            ->subject($this->invitedByName.' has invited you to '.$workspace.' on '.config('app.name'))
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line($this->invitedByName.' has invited you to join '.$workspace.' on '.config('app.name').', an accounting and ERP platform.')
            ->action('Set your password and get started', $this->url)
            ->line('This invitation expires in 7 days.')
            ->line('If you were not expecting this invitation, you can ignore this message — no account will be activated until you set a password.')
            ->salutation('— The '.config('app.name').' team');
    }
}
