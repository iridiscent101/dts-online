<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\SendAfterResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        $this->emailClient($document, ['SDO-Records Unit']);
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
        }
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

        // Sent after the routing is saved; no queue worker needed.
        SendAfterResponse::mail(fn () => Notification::route('mail', $email)->notifyNow($notification));
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
