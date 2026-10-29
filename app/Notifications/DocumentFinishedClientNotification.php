<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Tells the sender (the email they gave, if any) that their document is done: released to them, or finished by every
 * office it was routed to.
 */
class DocumentFinishedClientNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 60;

    public const RELEASED = 'released';

    public const COMPLETED = 'completed';

    public function __construct(
        public string $trackingNumber,
        public string $kind,
        public string $office,
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
        $message = (new MailMessage)->greeting('Good day!');

        if ($this->kind === self::RELEASED) {
            $message->subject('Your document '.$this->trackingNumber.' was released')
                ->line('Your document has been released by the '.$this->office.'.');
        } else {
            $message->subject('Your document '.$this->trackingNumber.' is complete')
                ->line('All offices have finished processing your document. The last office to finish was the '.$this->office.'.');
        }

        return $message
            ->line('Tracking number: '.$this->trackingNumber)
            ->action('Track your document', EmailLink::route('tracking.index', ['tracking' => $this->trackingNumber]))
            ->line(DocumentRoutedClientNotification::SPAM_NOTE)
            ->salutation(new HtmlString('Regards,<br>'.e(config('dts.mail_sign_off'))));
    }
}
