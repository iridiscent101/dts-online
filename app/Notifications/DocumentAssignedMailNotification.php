<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentAssignedMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId,
        public string $trackingNumber,
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
            ->subject('DocuTrackS Notification: '.$this->trackingNumber)
            ->line('A document has been routed to your office.')
            ->action('View document', route('platform.documents.view', $this->documentId));
    }
}
