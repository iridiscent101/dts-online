<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyNotificationEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public string $addressHash,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your DocuTrackS email alerts')
            ->line('Confirm this address to receive document assignment emails. This link expires in 24 hours.')
            ->action('Confirm email alerts', url(URL::temporarySignedRoute(
                'notification-email.verify',
                now()->addDay(),
                ['user' => $this->userId, 'hash' => $this->addressHash],
                absolute: false,
            )));
    }
}
