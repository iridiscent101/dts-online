<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentMovement;
use Illuminate\Notifications\Notification;
use Orchid\Platform\Notifications\DashboardChannel;

class DocumentAssignedNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Document $document,
        public string $action,
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
     * Get the database representation of the notification.
     *
     * @return array<string, int|string>
     */
    public function toDashboard(object $notifiable): array
    {
        $message = match ($this->action) {
            'submitted' => "{$this->document->tracking_number} was submitted to your office by a client.",
            'register' => "{$this->document->tracking_number} was registered for your office by {$this->actorName}.",
            DocumentMovement::ACTION_SENT => "{$this->document->tracking_number} was sent to your office by {$this->actorName}.",
            DocumentMovement::ACTION_FORWARDED, DocumentMovement::ACTION_FORWARD => "{$this->document->tracking_number} was forwarded to your office by {$this->actorName}.",
            DocumentMovement::ACTION_RETURNED => "{$this->document->tracking_number} was returned to your office by {$this->actorName}.",
            DocumentMovement::ACTION_RESUBMITTED => "{$this->document->tracking_number} was resubmitted to your office by {$this->actorName}.",
            DocumentMovement::ACTION_RESTORED => "{$this->document->tracking_number} was restored to your office by {$this->actorName}.",
            default => "{$this->document->tracking_number} was forwarded to your office by {$this->actorName}.",
        };

        return [
            'title' => $this->document->tracking_number,
            'document_id' => $this->document->getKey(),
            'tracking_number' => $this->document->tracking_number,
            'subject' => $this->document->subject,
            'action' => route('platform.notifications.open', $this->id),
            'event' => $this->action,
            'office' => $this->document->current_office,
            'message' => $message,
            'type' => 'primary',
        ];
    }
}
