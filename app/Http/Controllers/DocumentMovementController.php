<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentMovementRequest;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Orchid\Support\Facades\Toast;

class DocumentMovementController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreDocumentMovementRequest $request, Document $document): RedirectResponse
    {
        $workflow = $request->validated('workflow');
        $user = $request->user();

        $transition = DB::transaction(function () use ($document, $workflow, $user): array {
            $currentDocument = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            abort_unless($this->canManage($user, $currentDocument), 403, 'This document is assigned to another office.');

            $action = $workflow['action'];
            $this->ensureTransitionIsAllowed($currentDocument, $action);

            $destination = $workflow['destination'] ?? $currentDocument->current_office;
            $remarks = filled($workflow['remarks'] ?? null) ? trim($workflow['remarks']) : null;
            $fromOffice = $currentDocument->current_office;
            $fromStatus = $currentDocument->status;
            [$office, $status] = $this->transition($currentDocument, $action, $destination);

            $currentDocument->movements()->create([
                'action' => $action,
                'from_office' => $currentDocument->current_office,
                'to_office' => $office,
                'from_status' => $currentDocument->status,
                'to_status' => $status,
                'remarks' => $remarks,
                'acted_by' => $user->getAuthIdentifier(),
            ]);

            $currentDocument->update([
                'current_office' => $office,
                'status' => $status,
                'remarks' => $remarks,
            ]);

            $currentDocument->recordActivity($this->activityDescription($action), $user, [
                'action' => $action,
                'from_office' => $fromOffice,
                'to_office' => $office,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'remarks' => $remarks,
            ]);

            return ['action' => $action, 'office' => $office];
        });

        $document->refresh();
        $this->notifyAssignedOffice($user, $document, $transition['action'], $transition['office']);

        Toast::success($this->successMessage($document, $workflow['action'], $workflow['destination'] ?? null));

        return redirect()->route('platform.documents.view', $document);
    }

    private function canManage(User $user, Document $document): bool
    {
        return $user->isAdministrator() || $user->office === $document->current_office;
    }

    private function ensureTransitionIsAllowed(Document $document, string $action): void
    {
        $allowed = match ($action) {
            'acknowledge' => in_array($document->status, ['Awaiting receipt', 'Forwarded'], true),
            'forward', 'archive' => $document->status === 'Received',
            'restore' => $document->status === 'Archived',
            default => false,
        };

        if (! $allowed) {
            $actionLabel = match ($action) {
                'acknowledge' => 'acknowledged',
                'forward' => 'forwarded',
                'archive' => 'archived',
                'restore' => 'restored',
            };

            throw ValidationException::withMessages([
                'workflow.action' => "This document cannot be {$actionLabel} while its status is {$document->status}.",
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function transition(Document $document, string $action, string $destination): array
    {
        if ($action === 'forward' && $destination === $document->current_office) {
            throw ValidationException::withMessages([
                'workflow.destination' => 'Select a different office to forward this document.',
            ]);
        }

        return match ($action) {
            'acknowledge' => [$document->current_office, 'Received'],
            'forward' => [$destination, 'Forwarded'],
            'archive' => [$document->current_office, 'Archived'],
            'restore' => [$destination, 'Received'],
        };
    }

    private function successMessage(Document $document, string $action, ?string $destination): string
    {
        return match ($action) {
            'acknowledge' => "Receipt for {$document->tracking_number} was acknowledged.",
            'forward' => "{$document->tracking_number} was forwarded to {$destination}.",
            'archive' => "{$document->tracking_number} was archived.",
            'restore' => "{$document->tracking_number} was restored to {$destination}.",
        };
    }

    private function activityDescription(string $action): string
    {
        return match ($action) {
            'acknowledge' => 'Receipt acknowledged',
            'forward' => 'Document forwarded',
            'archive' => 'Document archived',
            'restore' => 'Document restored',
        };
    }

    private function notifyAssignedOffice(User $actor, Document $document, string $action, string $office): void
    {
        if (! in_array($action, ['forward', 'restore'], true)) {
            return;
        }

        $recipients = User::query()
            ->where('office', $office)
            ->whereKeyNot($actor->getKey())
            ->get();

        Notification::send($recipients, new DocumentAssignedNotification($document, $action, $actor->name));
    }
}
