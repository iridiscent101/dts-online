<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Notifications\DocumentNotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Signature('documents:notify-due')]
#[Description('Send office notifications for documents that are due soon or overdue')]
class SendDocumentDueNotifications extends Command
{
    public function handle(DocumentNotificationDispatcher $notifications): int
    {
        $sent = 0;
        $today = today('Asia/Manila')->toDateString();
        $dueSoonEnd = today('Asia/Manila')->addDays(2)->toDateString();

        Document::query()
            ->where(function (Builder $query): void {
                $query->whereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments->whereIn('status', DocumentMovement::IN_PROGRESS_STATUSES))
                    ->orWhere(fn (Builder $legacy): Builder => $legacy->doesntHave('officeAssignments')->whereIn('status', DocumentMovement::IN_PROGRESS_STATUSES));
            })
            ->with(['officeAssignments' => fn (HasMany $assignments): HasMany => $assignments->whereIn('status', DocumentMovement::IN_PROGRESS_STATUSES)])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', $dueSoonEnd)
            ->lazyById()
            ->each(function (Document $document) use ($notifications, $today, &$sent): void {
                $severity = $document->due_at->toDateString() < $today ? 'overdue' : 'due_soon';
                $offices = $document->officeAssignments->pluck('office')->all() ?: [$document->current_office];
                $sent += $notifications->due($document, $severity, $offices);
            });

        $this->info("Sent {$sent} document due notification(s).");

        return self::SUCCESS;
    }
}
