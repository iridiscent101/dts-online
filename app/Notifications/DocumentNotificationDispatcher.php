<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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

        $this->emailAssignedOffices($document, $offices);
    }

    public function submitted(Document $document): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentAssignedNotification($document, 'submitted', 'a client'),
        );

        $this->emailAssignedOffices($document, ['SDO-Records Unit']);
    }

    public function changed(Document $document, string $change, User $actor): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentChangedNotification($document, $change, $actor->name),
        );
    }

    /**
     * @param  list<string>|null  $offices
     */
    private function emailAssignedOffices(Document $document, ?array $offices): void
    {
        if (! config('mail.document_assignment_notifications_enabled')) {
            return;
        }

        $officeNames = $offices ?? $document->assignedOfficeNames()->all();
        $recipients = User::query()
            ->where(function (Builder $query) use ($officeNames): void {
                $query->whereHas('offices', fn (Builder $offices): Builder => $offices->whereIn('name', $officeNames))
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->where('slug', User::ADMIN_ROLE_SLUG));
            })
            ->whereNotNull('notification_email')
            ->where('notification_email', '!=', '')
            ->whereNotNull('notification_email_verified_at')
            ->get()
            ->unique('notification_email')
            ->values();

        $mail = new DocumentAssignedMailNotification($document->getKey(), $document->tracking_number);

        if (config('queue.default') === 'sync') {
            DB::afterCommit(fn () => rescue(fn () => Notification::send($recipients, $mail)));

            return;
        }

        Notification::send($recipients, $mail);
    }

    /**
     * @return Collection<int, User>
     */
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
            ->get();
    }
}
