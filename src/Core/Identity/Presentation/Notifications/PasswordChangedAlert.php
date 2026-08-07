<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Notifications;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation that a credential changed.
 *
 * Sent to the account holder whoever initiated the change, and deliberately not suppressible.
 * An unexpected "your password was changed" mail is the single most effective way an account
 * takeover gets noticed by the person best placed to act on it.
 */
final class PasswordChangedAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $initiatedBy)
    {
        $this->onQueue('critical');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $context = match ($this->initiatedBy) {
            'self-service' => 'You changed your password.',
            'reset-link' => 'Your password was changed using a reset link.',
            'invitation' => 'Your account password has been set.',
            'administrative' => 'An administrator in your workspace reset your password.',
            default => 'Your password was changed.',
        };

        return (new MailMessage)
            ->subject('Your '.config('app.name').' password was changed')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line($context)
            ->line('When: '.now()->timezone($notifiable->effectiveTimezone())->toDayDateTimeString().' ('.$notifiable->effectiveTimezone().')')
            ->line('If this was not you, your account may be compromised. Reset your password immediately and contact your administrator.')
            ->salutation('— The '.config('app.name').' team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'security.password_changed',
            'initiated_by' => $this->initiatedBy,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
