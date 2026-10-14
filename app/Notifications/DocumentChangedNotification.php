<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;
use Orchid\Platform\Notifications\DashboardChannel;

class DocumentChangedNotification extends Notification
{
    public function __construct(
        public Document $document,
        public string $change,
        public string $actorName,
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
        $message = match ($this->change) {
            'details' => "{$this->document->tracking_number} details were updated by {$this->actorName}.",
            'attachments_added' => "Files were added to {$this->document->tracking_number} by {$this->actorName}.",
            'attachment_replaced' => "A file was replaced on {$this->document->tracking_number} by {$this->actorName}.",
            'attachment_removed' => "A file was removed from {$this->document->tracking_number} by {$this->actorName}.",
            default => "{$this->document->tracking_number} was updated by {$this->actorName}.",
        };

        return [
            'title' => $this->document->tracking_number,
            'document_id' => $this->document->getKey(),
            'tracking_number' => $this->document->tracking_number,
            'subject' => $this->document->subject,
            'action' => route('platform.notifications.open', $this->id),
            'event' => $this->change,
            'office' => $this->document->current_office,
            'message' => $message,
            'type' => 'info',
        ];
    }
}
