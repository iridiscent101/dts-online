<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Orchid\Platform\Notifications\DashboardChannel;

class SystemHealthNotification extends Notification
{
    use Queueable;

    public const ISSUE = 'issue';

    public const RECOVERY = 'recovery';

    /**
     * @param  array{overall: string, checked_at: string, checks: array<int, array{id: string, label: string, status: string, summary: string, details: string}>}  $report
     */
    public function __construct(
        public array $report,
        public string $event,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return (bool) config('health.alert_mail_enabled', false)
            ? [DashboardChannel::class, 'mail']
            : [DashboardChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->notificationData();

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['message'])
            ->action('Open System Health', EmailLink::route('platform.systems.health'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboard(object $notifiable): array
    {
        return $this->notificationData();
    }

    /**
     * @return array{title: string, message: string, action: string, event: string, type: string, route: string, status: string, checks: array<int, array{label: string, status: string, summary: string}>}
     */
    private function notificationData(): array
    {
        $isRecovery = $this->event === self::RECOVERY;
        $affectedChecks = collect($this->report['checks'])
            ->reject(fn (array $check): bool => $check['status'] === 'healthy')
            ->map(fn (array $check): array => [
                'label' => $check['label'],
                'status' => $check['status'],
                'summary' => $check['summary'],
            ])
            ->values()
            ->all();

        $summary = collect($affectedChecks)
            ->map(fn (array $check): string => $check['label'].': '.$check['summary'])
            ->implode('; ');

        return [
            'title' => $isRecovery ? 'System health recovered' : 'System health needs attention',
            'message' => $isRecovery
                ? 'All monitored system services have returned to a healthy state.'
                : ($summary !== '' ? $summary : 'One or more system checks require attention.'),
            'action' => route('platform.notifications.open', $this->id),
            'event' => $isRecovery ? 'health_recovered' : 'health_'.$this->report['overall'],
            'type' => $isRecovery ? 'success' : ($this->report['overall'] === 'critical' ? 'danger' : 'warning'),
            'route' => 'platform.systems.health',
            'status' => $this->report['overall'],
            'checks' => $affectedChecks,
        ];
    }
}
