<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Forgot password: the temporary password, sent to the staff member's recovery email
 * (User::routeNotificationForMail). Sent right away, never queued, so the password is not stored in the jobs table.
 */
class TemporaryPasswordNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public string $temporaryPassword,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your temporary password')
            ->greeting('Good day!')
            ->line('Someone asked for a new password for your Document Tracking System account. Use this temporary password to sign in:')
            ->line(new HtmlString('<strong style="font-size: 18px; letter-spacing: 1px;">'.e($this->temporaryPassword).'</strong>'))
            ->line('It works for '.(int) config('dts.temporary_password_minutes').' minutes. Right after you sign in with it you will be asked to set a new password of your own: type this temporary password as your current password.')
            ->action('Sign in', EmailLink::route('platform.login'))
            ->line('Your current password still works until you use this one, so if you did not ask for this you can ignore this email. If it keeps happening, tell your system administrator.')
            ->line(DocumentRoutedClientNotification::SPAM_NOTE)
            ->salutation(new HtmlString('Regards,<br>'.e(config('dts.mail_sign_off'))));
    }
}
