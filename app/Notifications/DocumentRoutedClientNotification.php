<?php

namespace App\Notifications;

use App\Support\EmailLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Tells the sender (the email they gave on the form, if any) that their document was routed to an office.
 * Staff are not emailed; they see document alerts in the app.
 */
class DocumentRoutedClientNotification extends Notification
{
    public const SPAM_NOTE = 'If you do not see our emails in your Inbox, please check your Spam folder and mark them as "Report not spam" so future updates reach your Inbox.';

    /**
     * @param  list<string>  $offices
     */
    public function __construct(
        public string $trackingNumber,
        public array $offices,
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
            ->subject('Your document '.$this->trackingNumber.' was routed')
            ->greeting('Good day!')
            ->line('Your document has been routed to the '.collect($this->offices)->join(', ', ' and ').'.')
            ->line('Tracking number: '.$this->trackingNumber)
            ->action('Track your document', EmailLink::route('tracking.index', ['tracking' => $this->trackingNumber]))
            ->line(self::SPAM_NOTE)
            ->salutation(new HtmlString('Regards,<br>'.e(config('dts.mail_sign_off'))));
    }
}
