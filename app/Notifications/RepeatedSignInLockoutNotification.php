<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Orchid\Platform\Notifications\DashboardChannel;

/**
 * Tells administrators that one email was locked out of sign-in several times in a day from one computer: someone
 * may be guessing the password, or a staff member has forgotten it.
 */
class RepeatedSignInLockoutNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $email,
        public string $ip,
        public int $lockouts,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return (bool) config('health.alert_mail_enabled', false)
            ? [DashboardChannel::class, 'mail']
            : [DashboardChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->notificationData();

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['message'])
            ->action('Open sign-in activity', EmailLink::route('platform.systems.sign-in-activity'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboard(object $notifiable): array
    {
        return $this->notificationData();
    }

    /**
     * @return array{title: string, message: string, action: string, event: string, type: string, route: string}
     */
    private function notificationData(): array
    {
        return [
            'title' => 'Repeated failed sign-ins',
            'message' => "Sign-in for {$this->email} was locked {$this->lockouts} times today from {$this->ip}. "
                .'Someone may be guessing the password, or the staff member may need a new one.',
            'action' => route('platform.notifications.open', $this->id),
            'event' => 'sign_in_lockouts',
            'type' => 'warning',
            'route' => 'platform.systems.sign-in-activity',
        ];
    }
}
