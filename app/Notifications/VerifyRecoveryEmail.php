<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyRecoveryEmail extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $path = URL::temporarySignedRoute('recovery-email.verify', now()->addHour(), ['user' => $notifiable->getKey(), 'token' => $this->token], absolute: false);

        return (new MailMessage)
            ->subject('Verify your recovery email')
            ->line('Confirm this email address to use it for password resets.')
            ->action('Verify recovery email', EmailLink::to($path))
            ->line('This link expires in one hour. If you did not request this, ignore this message.');
    }
}
