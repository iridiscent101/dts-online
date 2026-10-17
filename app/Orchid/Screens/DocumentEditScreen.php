<?php

namespace App\Orchid\Screens;

use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class DocumentEditScreen extends Screen
{
    private ?Document $document = null;

    public function query(Document $document): iterable
    {
        $user = request()->user();

        abort_unless($user !== null && $document->isVisibleTo($user), 404);
        abort_unless($document->canBeEditedBy($user), 403);

        $this->document = $document;

        return ['document' => $this->document];
    }

    public function name(): ?string
    {
        return $this->document === null ? 'Edit document' : 'Edit '.$this->document->tracking_number;
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [];
    }

    /**
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [Layout::view('orchid.document-edit')];
    }

    public function permission(): ?iterable
    {
        return ['platform.documents.edit'];
    }

    public function save(
        UpdateDocumentRequest $request,
        Document $document,
        DocumentNotificationDispatcher $notifications,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();
        $data = $validated['document'];
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $before = [];
        $changedFields = [];
        $routeChanges = ['before' => [], 'after' => [], 'added' => [], 'removed' => []];
        $destinations = $data['destinations'] ?? null;

        DB::transaction(function () use ($data, $document, $user, $destinations, $notifications, &$before, &$changedFields, &$routeChanges): void {
            Document::query()->lockForUpdate()->findOrFail($document->getKey());
            $document->refresh();
            abort_unless($document->canBeEditedBy($user), 403);

            if (! hash_equals($document->detailsFingerprint(), $data['details_fingerprint'])) {
                throw ValidationException::withMessages([
                    'document.details_fingerprint' => 'Document details changed while you were editing. Refresh the document and try again.',
                ]);
            }

            $detailsLocked = $document->detailsRequireUnlockFor($user);

            $before = $document->only([
                'subject',
                'document_type',
                'external_reference',
                'description',
                'origin',
                'sender',
                'received_at',
            ]);

            $document->update([
                'subject' => $data['title'],
                'document_type' => $data['type'],
                'external_reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'origin' => $data['origin'],
                'sender' => $data['sender'],
                'received_at' => $data['received'] ?? $document->received_at,
            ]);

            $changedFields = array_values(array_diff(array_keys($document->getChanges()), ['updated_at']));
            $correctionReason = trim((string) ($data['correction_reason'] ?? ''));

            // Locked details can still be corrected, but only with a reason; the transaction undoes the update.
            if ($changedFields !== [] && $detailsLocked && $correctionReason === '') {
                throw ValidationException::withMessages([
                    'document.correction_reason' => 'The details of this document are locked. Unlock them and give a reason for the correction.',
                ]);
            }

            if ($document->canEditOfficeAssignmentsBy($user)
                && $document->routesAreEditable()
                && is_array($destinations)
                && $destinations !== []) {
                $routeChanges = $this->updateRoutes($document, $destinations, $user, $data['route_fingerprint'] ?? null, $correctionReason);
            }

            if ($changedFields !== []) {
                $beforeValues = array_intersect_key($before, array_flip($changedFields));
                $afterValues = $document->only($changedFields);

                $document->recordActivity('Document details updated', $user, [
                    'action' => 'update',
                    'changed_fields' => $changedFields,
                    'before' => $beforeValues,
                    'after' => $afterValues,
                    // Locked details were unlocked to correct them; why is kept with the change.
                    ...($detailsLocked ? ['correction_reason' => $correctionReason] : []),
                ]);
                $notifications->changed($document, 'details', $user);
            }

            if ($routeChanges['added'] !== [] || $routeChanges['removed'] !== []) {
                $document->recordActivity('Document routes updated', $user, [
                    'action' => 'routes',
                    'office' => $document->current_office,
                    'before' => $routeChanges['before'],
                    'after' => $routeChanges['after'],
                    'added' => $routeChanges['added'],
                    'removed' => $routeChanges['removed'],
                    'correction_reason' => $correctionReason,
                ]);

                if ($routeChanges['added'] !== []) {
                    $notifications->assigned($document, 'routes', $user, $routeChanges['added']);
                }
            }
        });

        $routesChanged = $routeChanges['added'] !== [] || $routeChanges['removed'] !== [];

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Document details saved.']);
        }

        if ($changedFields !== [] && $routesChanged) {
            Toast::success('Document details and routes were updated.');
        } elseif ($changedFields !== []) {
            Toast::success('Document details were updated.');
        } elseif ($routesChanged) {
            Toast::success('Document routes were updated.');
        } else {
            Toast::info('No document details changed.');
        }

        return redirect()->route('platform.documents.view', $document);
    }

    /**
     * Synchronize the document's office assignments with the submitted routes,
     * recording a movement for each newly assigned office.
     *
     * @param  list<string>  $destinations
     * @return array{before: list<string>, after: list<string>, added: list<string>, removed: list<string>}
     */
    private function updateRoutes(Document $document, array $destinations, User $user, ?string $routeFingerprint, string $correctionReason): array
    {
        $destinations = array_values($destinations);
        $existing = $document->officeAssignments()->pluck('office')->all();
        $added = array_values(array_diff($destinations, $existing));
        $removed = array_values(array_diff($existing, $destinations));

        if ($added === [] && $removed === []) {
            return ['before' => $existing, 'after' => $destinations, 'added' => [], 'removed' => []];
        }

        if (! hash_equals($document->routeFingerprint(), $routeFingerprint ?? '')) {
            throw ValidationException::withMessages([
                'document.route_fingerprint' => 'Routes changed while you were editing. Refresh the document and try again.',
            ]);
        }

        if ($correctionReason === '') {
            throw ValidationException::withMessages([
                'document.correction_reason' => 'Give a reason for changing the assigned offices.',
            ]);
        }

        if ($removed !== []) {
            $startedOffice = $document->officeAssignments()
                ->whereIn('office', $removed)
                ->whereNotIn('status', [DocumentMovement::STATUS_AWAITING_RECEIPT, DocumentMovement::STATUS_FORWARDED])
                ->value('office');

            if ($startedOffice !== null) {
                throw ValidationException::withMessages([
                    'document.destinations' => "{$startedOffice} has started work and cannot be removed from this document.",
                ]);
            }
        }

        if ($document->registered_office === null
            || $document->hasBeenProcessed()
            || $document->officeAssignments()->whereIn('office', $removed)->where('status', DocumentMovement::STATUS_FORWARDED)->exists()) {
            throw ValidationException::withMessages([
                'document.destinations' => 'After processing begins, use Forward for new routes or Correct route for a pending destination.',
            ]);
        }

        if ($removed !== []) {
            foreach ($document->officeAssignments()->whereIn('office', $removed)->get() as $assignment) {
                $document->movements()->create([
                    'action' => DocumentMovement::ACTION_CANCELLED,
                    'from_office' => null,
                    'to_office' => $assignment->office,
                    'from_status' => $assignment->status,
                    'to_status' => DocumentMovement::STATUS_CANCELLED,
                    'remarks' => 'Correction reason: '.$correctionReason,
                    'acted_by' => $user->getAuthIdentifier(),
                ]);
                $assignment->delete();
            }
        }

        foreach ($added as $office) {
            $movement = $document->movements()->create([
                'action' => DocumentMovement::ACTION_SENT,
                'from_office' => null,
                'to_office' => $office,
                'from_status' => null,
                'to_status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
                'remarks' => null,
                'acted_by' => $user->getAuthIdentifier(),
            ]);

            $document->officeAssignments()->create([
                'office' => $office,
                'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
                'assigned_by' => $user->getAuthIdentifier(),
                'movement_id' => $movement->getKey(),
            ]);
        }

        $assignments = $document->officeAssignments()->get()->keyBy('office');
        $unfinished = fn (string $office): bool => ! in_array($assignments[$office]->status, DocumentMovement::FINISHED_STATUSES, true);
        $currentOffice = in_array($document->current_office, $destinations, true)
            ? $document->current_office
            : (collect($destinations)->first(fn (string $office): bool => $unfinished($office)
                && ! in_array($office, ['SDO-Records Unit', 'Records Unit'], true))
                ?? collect($destinations)->first($unfinished)
                ?? $destinations[0]);

        $document->update([
            'current_office' => $currentOffice,
            'status' => $assignments[$currentOffice]->status,
        ]);

        return ['before' => $existing, 'after' => $destinations, 'added' => $added, 'removed' => $removed];
    }
}
