<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
    }

    public function submitted(Document $document): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentAssignedNotification($document, 'submitted', 'a client'),
        );
    }

    public function changed(Document $document, string $change, User $actor): void
    {
        Notification::send(
            $this->recipients($document),
            new DocumentChangedNotification($document, $change, $actor->name),
        );
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
                $query->whereIn('office', $officeNames)
                    ->orWhereHas('offices', fn (Builder $offices): Builder => $offices->whereIn('name', $officeNames))
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->where('slug', User::ADMIN_ROLE_SLUG));
            })
            ->get();
    }
}
