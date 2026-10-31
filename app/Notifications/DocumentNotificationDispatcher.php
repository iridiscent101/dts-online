<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentPushSubscription;
use App\Models\Office;
use App\Models\User;
use App\Services\FirebaseMessaging;
use App\Support\SendAfterResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

class DocumentNotificationDispatcher
{
    /**
     * @param  list<string>|null  $offices
     */
    public function assigned(Document $document, string $action, User $actor, ?array $offices = null): void
    {
        Notification::send(
            $this->recipients($document, $offices),
            new DocumentAssignedNotification($document, $action, $actor->name),
        );

        $this->emailClient($document, $offices);
    }

    public function submitted(Document $document): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentAssignedNotification($document, 'submitted', 'a client'),
        );

        $this->emailClient($document, [Office::RECORDS_UNIT_NAME]);
    }

    public function changed(Document $document, string $change, User $actor): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentChangedNotification($document, $change, $actor->name),
        );
    }

    /**
     * Emails the sender, when they gave an address, that the document was routed to these offices. Staff are not
     * emailed about documents; they get the in-app alerts above.
     *
     * @param  list<string>|null  $offices
     */
    private function emailClient(Document $document, ?array $offices): void
    {
        $officeNames = collect($offices ?? $document->assignedOfficeNames()->all())->filter()->unique()->values()->all();
        if ($officeNames === []) {
            return;
        }

        $this->sendToClient($document, new DocumentRoutedClientNotification($document->tracking_number, $officeNames));
        $this->pushToClient(
            $document,
            "Your document {$document->tracking_number} was routed",
            'It was routed to the '.collect($officeNames)->join(', ', ' and ').'.',
        );
    }

    /**
     * Tells the sender their document is done: when an office releases it to them, or when the last office working on
     * it completes its part (call this after the routing is saved, with the document refreshed).
     */
    public function finished(Document $document, string $action, string $office): void
    {
        if ($action === DocumentMovement::ACTION_RELEASED) {
            $this->sendToClient($document, new DocumentFinishedClientNotification(
                $document->tracking_number,
                DocumentFinishedClientNotification::RELEASED,
                $office,
            ));
            $this->pushToClient($document, "Your document {$document->tracking_number} was released", "It was released by the {$office}.");

            return;
        }

        $allOfficesFinished = $document->officeAssignments()
            ->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)
            ->doesntExist();
        if ($action === DocumentMovement::ACTION_COMPLETED && $allOfficesFinished) {
            $this->sendToClient($document, new DocumentFinishedClientNotification(
                $document->tracking_number,
                DocumentFinishedClientNotification::COMPLETED,
                $office,
            ));
            $this->pushToClient($document, "Your document {$document->tracking_number} is complete", 'All offices have finished processing it.');
        }
    }

    /**
     * Pushes an update to the client's phones that asked for it on the tracking or receipt page ("Get updates on this
     * phone"). Separate from the email: it needs no email address and does not count toward the email limits. Sent once
     * the save commits; a token Firebase reports as dead is deleted. Tapping it opens the tracking page.
     */
    private function pushToClient(Document $document, string $title, string $body): void
    {
        $firebase = app(FirebaseMessaging::class);
        $tokens = $document->pushSubscriptions()->orderBy('id')->pluck('token');
        if ($tokens->isEmpty() || ! $firebase->configured()) {
            return;
        }

        $link = route('tracking.index', ['tracking' => $document->tracking_number]);
        $documentId = $document->getKey();
        SendAfterResponse::mail(function () use ($firebase, $tokens, $title, $body, $link, $documentId): void {
            foreach ($tokens as $token) {
                $subscription = DocumentPushSubscription::query()->where('document_id', $documentId)->where('token', $token);
                match ($firebase->send($token, $title, $body, $link)) {
                    FirebaseMessaging::INVALID_TOKEN => $subscription->delete(),
                    FirebaseMessaging::SENT => $subscription->update(['last_used_at' => now()]),
                    default => null,
                };
            }
        });
    }

    private function sendToClient(Document $document, \Illuminate\Notifications\Notification $notification): void
    {
        $email = trim((string) $document->client_email);
        if (! config('mail.document_assignment_notifications_enabled') || $email === '') {
            return;
        }

        // All client emails together stop for the day at the limit, so the Gmail account stays under its own sending
        // limit. Routing still works; only the email is skipped.
        $dailyKey = 'client-emails:'.today('Asia/Manila')->toDateString();
        if (! RateLimiter::attempt($dailyKey, config('dts.client_email.daily_limit'), fn (): bool => true, 86400)) {
            Log::warning('Client email skipped: the daily client email limit was reached.', [
                'tracking_number' => $document->tracking_number,
            ]);

            return;
        }

        if (config('queue.default') === 'sync') {
            SendAfterResponse::mail(fn () => retry(3, fn () => Notification::route('mail', $email)->notifyNow($notification), 1000));

            return;
        }

        DB::afterCommit(fn () => rescue(fn () => Notification::route('mail', $email)->notify($notification)));
    }

    /**
     * @param  list<string>|null  $offices
     * @return Collection<int, User>
     */
    private function recipients(Document $document, ?array $offices = null): Collection
    {
        $officeNames = collect($offices ?? $document->assignedOfficeNames()->all())
            ->filter()
            ->unique()
            ->values();

        return User::query()
            ->where(function (Builder $query) use ($officeNames): void {
                // Office memberships are the only record of a user's offices (users.office was dropped).
                $query->whereHas('offices', fn (Builder $offices): Builder => $offices->whereIn('name', $officeNames))
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->where('slug', User::ADMIN_ROLE_SLUG));
            })
            ->active()
            ->get();
    }
}
