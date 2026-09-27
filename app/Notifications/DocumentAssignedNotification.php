<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

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
        return ['database'];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, int|string>
     */
    public function toDatabase(object $notifiable): array
    {
        $verb = $this->action === 'restore' ? 'restored' : 'forwarded';

        return [
            'document_id' => $this->document->getKey(),
            'tracking_number' => $this->document->tracking_number,
            'subject' => $this->document->subject,
            'action' => $this->action,
            'office' => $this->document->current_office,
            'message' => "{$this->document->tracking_number} was {$verb} to your office by {$this->actorName}.",
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'document-assigned';
    }
}
