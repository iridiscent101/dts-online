<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyNotificationEmail extends Notification
{
    public function __construct(
        public int $userId,
        public string $addressHash,
    ) {}

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
            ->subject('Confirm your recovery email')
            ->line('Confirm this address so a password reset link can be sent here if you forget yours. This link expires in 24 hours.')
            ->action('Confirm recovery email', EmailLink::to(URL::temporarySignedRoute(
                'notification-email.verify',
                now()->addDay(),
                ['user' => $this->userId, 'hash' => $this->addressHash],
                absolute: false,
            )))
            ->line(DocumentRoutedClientNotification::SPAM_NOTE);
    }
}
