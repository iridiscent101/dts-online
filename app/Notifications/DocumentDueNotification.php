<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;
use Orchid\Platform\Notifications\DashboardChannel;

class DocumentDueNotification extends Notification
{
    public function __construct(
        public Document $document,
        public string $severity,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DashboardChannel::class];
    }

    /**
     * @return array<string, int|string>
     */
    public function toDashboard(object $notifiable): array
    {
        $dueDate = $this->document->due_at->toDateString();
        $message = match (true) {
            $this->severity === 'overdue' => "{$this->document->tracking_number} is overdue since {$this->document->due_at->format('M d, Y')}.",
            $dueDate === today('Asia/Manila')->toDateString() => "{$this->document->tracking_number} is due today.",
            default => "{$this->document->tracking_number} is due on {$this->document->due_at->format('M d, Y')}.",
        };

        return [
            'title' => $this->document->tracking_number,
            'document_id' => $this->document->getKey(),
            'tracking_number' => $this->document->tracking_number,
            'subject' => $this->document->subject,
            'action' => route('platform.notifications.open', $this->id),
            'event' => $this->severity,
            'office' => $this->document->current_office,
            'due_date' => $dueDate,
            'fingerprint' => $this->fingerprint(),
            'message' => $message,
            'type' => $this->severity === 'overdue' ? 'danger' : 'warning',
        ];
    }

    public function fingerprint(): string
    {
        return "{$this->document->getKey()}:{$this->document->due_at->toDateString()}:{$this->severity}";
    }
}
