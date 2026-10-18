<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentOfficeAssignment;
use App\Models\User;
use App\Support\StatusLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentRoutingService
{
    private const RECORDS_OFFICES = ['SDO-Records Unit', 'Records Unit'];

    public function correctPendingRoute(Document $document, User $user, string $sourceOffice, string $destinationOffice, string $replacementOffice, string $reason): string
    {
        return DB::transaction(function () use ($document, $user, $sourceOffice, $destinationOffice, $replacementOffice, $reason): string {
            $currentDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless(! $user->isReadOnlySupervisor()
                && $user->hasAccess('platform.index')
                && ($user->isAdministrator() || $user->isAssignedToOffice($sourceOffice)), 403);

            $assignment = $currentDocument->officeAssignments()->where('office', $destinationOffice)->first();
            if ($assignment !== null && ! in_array($assignment->status, [DocumentMovement::STATUS_AWAITING_RECEIPT, DocumentMovement::STATUS_FORWARDED], true)) {
                throw ValidationException::withMessages([
                    'destination_office' => 'This route can no longer be corrected because the office received it or the document is finished.',
                ]);
            }

            $originalMovement = $assignment?->movement;
            $isInitialSend = $originalMovement?->action === DocumentMovement::ACTION_SENT
                && $originalMovement->from_office === null
                && $currentDocument->registered_office === $sourceOffice;
            $isOfficeForward = $originalMovement?->from_office === $sourceOffice
                && in_array($originalMovement?->action, [DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED], true);
            abort_unless($originalMovement !== null
                && ($isInitialSend || $isOfficeForward), 403);

            $pendingStatus = $isInitialSend ? DocumentMovement::STATUS_AWAITING_RECEIPT : DocumentMovement::STATUS_FORWARDED;
            if ($currentDocument->isClosedRecord() || $assignment->status !== $pendingStatus) {
                throw ValidationException::withMessages([
                    'destination_office' => 'This route can no longer be corrected because the office received it or the document is finished.',
                ]);
            }

            if ($replacementOffice === $sourceOffice || $currentDocument->officeAssignments()->where('office', $replacementOffice)->exists()) {
                throw ValidationException::withMessages([
                    'replacement_office' => 'Choose a different office that is not already assigned.',
                ]);
            }

            $currentDocument->movements()->create([
                'action' => DocumentMovement::ACTION_CANCELLED,
                'from_office' => $isInitialSend ? null : $sourceOffice,
                'to_office' => $destinationOffice,
                'from_status' => $assignment->status,
                'to_status' => DocumentMovement::STATUS_CANCELLED,
                'remarks' => 'Correction reason: '.$reason,
                'acted_by' => $user->id,
            ]);
            $assignment->delete();

            $replacementMovement = $currentDocument->movements()->create([
                'action' => $isInitialSend ? DocumentMovement::ACTION_SENT : DocumentMovement::ACTION_FORWARDED,
                'from_office' => $isInitialSend ? null : $sourceOffice,
                'to_office' => $replacementOffice,
                'from_status' => $originalMovement->from_status,
                'to_status' => $pendingStatus,
                'remarks' => $originalMovement->remarks,
                'acted_by' => $user->id,
            ]);
            $currentDocument->officeAssignments()->create([
                'office' => $replacementOffice,
                'status' => $pendingStatus,
                'assigned_by' => $user->id,
                'movement_id' => $replacementMovement->id,
            ]);

            if ($currentDocument->current_office === $destinationOffice) {
                $currentDocument->update(['current_office' => $replacementOffice, 'status' => $pendingStatus]);
            }

            $currentDocument->recordActivity('Document route corrected', $user, [
                'office' => $sourceOffice,
                'from_office' => $sourceOffice,
                'to_office' => $replacementOffice,
                'old_destination' => $destinationOffice,
                'new_destination' => $replacementOffice,
                'correction_reason' => $reason,
            ]);

            return $replacementMovement->action;
        });
    }

    public function addAdditionalRoute(Document $document, User $user, string $sourceOffice, string $destinationOffice, ?string $remarks, string $reason): void
    {
        DB::transaction(function () use ($document, $user, $sourceOffice, $destinationOffice, $remarks, $reason): void {
            $currentDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless(! $user->isReadOnlySupervisor()
                && $user->hasAccess('platform.index')
                && ($user->isAdministrator() || $user->isAssignedToOffice($sourceOffice)), 403);

            if ($currentDocument->officeAssignments()->where('office', $sourceOffice)
                ->whereIn('status', DocumentMovement::FINISHED_STATUSES)->exists()) {
                throw ValidationException::withMessages([
                    'source_office' => 'This office has finished its route. Restore it before sending to another office.',
                ]);
            }

            $priorForward = $currentDocument->movements()
                ->whereIn('action', [DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED])
                ->where('from_office', $sourceOffice)
                ->latest('id')
                ->first();
            abort_unless($priorForward !== null, 403);

            if ($currentDocument->isClosedRecord() || $currentDocument->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
                throw ValidationException::withMessages(['destination_office' => 'Restore the document before adding another office.']);
            }

            if ($sourceOffice === $destinationOffice || $currentDocument->officeAssignments()->where('office', $destinationOffice)->exists()) {
                throw ValidationException::withMessages(['destination_office' => 'Choose a different office that is not already assigned.']);
            }

            $activeRoutes = $currentDocument->officeAssignments()
                ->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)
                ->count();
            if ($activeRoutes === 0 || $activeRoutes >= 5) {
                throw ValidationException::withMessages(['destination_office' => 'A document can have no more than five active office routes.']);
            }

            $movement = $currentDocument->movements()->create([
                'action' => DocumentMovement::ACTION_FORWARDED,
                'from_office' => $sourceOffice,
                'to_office' => $destinationOffice,
                'from_status' => $priorForward->from_status,
                'to_status' => DocumentMovement::STATUS_FORWARDED,
                'remarks' => $remarks,
                'acted_by' => $user->id,
            ]);
            $currentDocument->officeAssignments()->create([
                'office' => $destinationOffice,
                'status' => DocumentMovement::STATUS_FORWARDED,
                'assigned_by' => $user->id,
                'movement_id' => $movement->id,
            ]);
            $currentDocument->recordActivity('Document route added', $user, [
                'office' => $sourceOffice,
                'from_office' => $sourceOffice,
                'to_office' => $destinationOffice,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * @param  array{action: string, source_office?: string|null, destination?: string|null, destinations?: list<string>, remarks?: string|null, released_to?: string|null, recipient_signature?: string|null, destination_remarks?: list<string|null>}  $workflow
     * @return array{action: string, office: string, offices: list<string>}
     */
    public function route(Document $document, User $user, array $workflow): array
    {
        return DB::transaction(function () use ($document, $user, $workflow): array {
            $currentDocument = Document::query()
                ->with('officeAssignments')
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            $sourceOffice = filled($workflow['source_office'] ?? null) ? trim((string) $workflow['source_office']) : null;
            $workflowState = $this->workflowState($user, $currentDocument, $sourceOffice);
            abort_unless($workflowState !== null, 403, 'This document is assigned to another office.');

            $action = $this->normalizeAction($workflow['action']);
            abort_if($action === DocumentMovement::ACTION_RESTORED && ! $this->canRestore($user), 403);
            $this->ensureTransitionIsAllowed($user, $currentDocument, $workflowState, $action);

            if ($action === DocumentMovement::ACTION_COMPLETED
                && in_array($workflowState['office'], self::RECORDS_OFFICES, true)
                && $this->hasUnfinishedNonRecordsRoutes($currentDocument)) {
                throw ValidationException::withMessages([
                    'workflow.action' => 'Records Unit can complete the document after the other offices finish their routes.',
                ]);
            }

            $destinations = $this->destinations($workflow);
            $remarks = filled($workflow['remarks'] ?? null) ? trim((string) $workflow['remarks']) : null;
            $status = $this->statusForAction($action);
            $targetOffices = DocumentMovement::requiresDestination($action)
                ? $this->validateDestinations($destinations, $workflowState['office'], $action)
                : [$workflowState['office']];
            if (DocumentMovement::requiresDestination($action)) {
                $activeOtherRoutes = $currentDocument->officeAssignments
                    ->filter(fn (DocumentOfficeAssignment $assignment): bool => $assignment->office !== $workflowState['office']
                        && ! in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true))
                    ->count();
                if ($activeOtherRoutes + count($targetOffices) > 5) {
                    throw ValidationException::withMessages([
                        'workflow.destinations' => 'A document can have no more than five active office routes.',
                    ]);
                }

                $alreadyAssigned = $currentDocument->officeAssignments->first(fn (DocumentOfficeAssignment $assignment): bool => in_array($assignment->office, $targetOffices, true)
                    && ($action !== DocumentMovement::ACTION_RESTORED
                        || ! in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true)));

                $isPaperReturningToRecords = $alreadyAssigned !== null
                    && $action === DocumentMovement::ACTION_FORWARDED
                    && in_array($alreadyAssigned->office, self::RECORDS_OFFICES, true)
                    && $alreadyAssigned->status === DocumentMovement::STATUS_RECEIVED
                    && $alreadyAssigned->movement?->action === DocumentMovement::ACTION_RECEIVED;

                if ($alreadyAssigned !== null && ! $isPaperReturningToRecords) {
                    throw ValidationException::withMessages([
                        'workflow.destinations' => "This document is already assigned to {$alreadyAssigned->office}. Choose another destination office.",
                    ]);
                }
            }

            $destinationRemarks = DocumentMovement::requiresDestination($action)
                ? ($workflow['destination_remarks'] ?? [])
                : [];

            $primaryOffice = $targetOffices[0];

            if (DocumentMovement::requiresDestination($action)) {
                $currentDocument->officeAssignments()
                    ->where('office', $workflowState['office'])
                    ->whereNotIn('office', $targetOffices)
                    ->delete();
            }

            foreach ($targetOffices as $index => $targetOffice) {
                $movementRemarks = filled($destinationRemarks[$index] ?? null) ? trim((string) $destinationRemarks[$index]) : $remarks;
                $movement = $currentDocument->movements()->create([
                    'action' => $action,
                    'from_office' => $workflowState['office'],
                    'to_office' => $targetOffice,
                    'from_status' => $workflowState['status'],
                    'to_status' => $status,
                    'remarks' => $movementRemarks,
                    'released_to' => $action === DocumentMovement::ACTION_RELEASED ? ($workflow['released_to'] ?? null) : null,
                    'recipient_signature' => $action === DocumentMovement::ACTION_RELEASED ? ($workflow['recipient_signature'] ?? null) : null,
                    'acted_by' => $user->getAuthIdentifier(),
                ]);

                $currentDocument->officeAssignments()->updateOrCreate(
                    ['office' => $targetOffice],
                    [
                        'status' => $status,
                        'assigned_by' => $user->getAuthIdentifier(),
                        'movement_id' => $movement->getKey(),
                    ],
                );
            }

            $remainingAssignment = null;
            if (in_array($status, DocumentMovement::FINISHED_STATUSES, true)) {
                $assignments = $currentDocument->officeAssignments()
                    ->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)
                    ->oldest('id');
                $remainingAssignment = (clone $assignments)
                    ->whereNotIn('office', self::RECORDS_OFFICES)
                    ->first() ?? $assignments->first();
            }

            $currentDocument->update([
                'current_office' => $remainingAssignment?->office ?? $primaryOffice,
                'status' => $remainingAssignment?->status ?? $status,
                'remarks' => $movementRemarks,
            ]);

            return array_filter([
                'action' => $action,
                'office' => $primaryOffice,
                'offices' => $targetOffices,
            ], fn (mixed $value): bool => $value !== null);
        });
    }

    public function successMessage(Document $document, string $action, string|array|null $destination): string
    {
        $destinationLabel = $this->officeLabel($destination);

        return match ($action) {
            DocumentMovement::ACTION_ACKNOWLEDGE, DocumentMovement::ACTION_RECEIVED => "{$document->tracking_number} was received.",
            DocumentMovement::ACTION_SENT => "{$document->tracking_number} was sent to {$destinationLabel}.",
            DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED => "{$document->tracking_number} was forwarded to {$destinationLabel}.",
            DocumentMovement::ACTION_RETURNED => "{$document->tracking_number} was returned to {$destinationLabel}.",
            DocumentMovement::ACTION_RESUBMITTED => "{$document->tracking_number} was resubmitted to {$destinationLabel}.",
            DocumentMovement::ACTION_REVIEWED => "{$document->tracking_number} was reviewed.",
            DocumentMovement::ACTION_APPROVED => "{$document->tracking_number} was approved.",
            DocumentMovement::ACTION_REJECTED => "{$document->tracking_number} was rejected.",
            DocumentMovement::ACTION_ON_HOLD => "{$document->tracking_number} was placed on hold.",
            DocumentMovement::ACTION_RELEASED => "{$document->tracking_number} was released.",
            DocumentMovement::ACTION_COMPLETED => "{$document->tracking_number} was completed.",
            DocumentMovement::ACTION_ARCHIVE => "{$document->tracking_number} was archived.",
            DocumentMovement::ACTION_RESTORED => "{$document->tracking_number} was restored to {$destinationLabel}.",
        };
    }

    public function canManage(User $user, Document $document): bool
    {
        return $this->workflowStates($user, $document)->isNotEmpty();
    }

    /**
     * @param  array{office: string, status: string}  $state
     * @return array<string, string>
     */
    public function availableActionOptions(User $user, Document $document, array $state): array
    {
        if ($state['status'] === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
            return [DocumentMovement::ACTION_RECEIVED => 'Confirm document received'];
        }

        $options = DocumentMovement::availableActionOptionsForStatus($state['status']);
        if (! $this->canRestore($user)) {
            unset($options[DocumentMovement::ACTION_RESTORED]);
        }
        $isRecordsOffice = in_array($state['office'], self::RECORDS_OFFICES, true);
        $hasOtherActiveRoutes = $this->hasUnfinishedNonRecordsRoutes($document);
        // Release hands the document back to the client: the Records Unit's job, and an administrator's from any office
        // that has received it. Either way, no other office may still be working on it.
        $mayRelease = $isRecordsOffice
            ? ! $hasOtherActiveRoutes
            : $user->isAdministrator() && ! $this->hasOtherUnfinishedRoutes($document, $state['office']);
        if (! $mayRelease || ! $this->canRelease($document, $state['office'])) {
            unset($options[DocumentMovement::ACTION_RELEASED]);
        }
        if ($isRecordsOffice && $hasOtherActiveRoutes) {
            unset($options[DocumentMovement::ACTION_COMPLETED]);
        }

        return $options;
    }

    private function canRestore(User $user): bool
    {
        return $user->isAdministrator() || $user->assignedOfficeNames()->intersect(self::RECORDS_OFFICES)->isNotEmpty();
    }

    private function hasUnfinishedNonRecordsRoutes(Document $document): bool
    {
        return $document->officeAssignments->contains(fn (DocumentOfficeAssignment $assignment): bool => ! in_array($assignment->office, self::RECORDS_OFFICES, true)
            && ! in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true));
    }

    private function hasOtherUnfinishedRoutes(Document $document, string $office): bool
    {
        return $document->officeAssignments->contains(fn (DocumentOfficeAssignment $assignment): bool => $assignment->office !== $office
            && ! in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true));
    }

    private function canRelease(Document $document, string $office): bool
    {
        $assignment = $document->officeAssignments->firstWhere('office', $office);
        $receipt = $assignment?->movement;

        return $assignment?->status === DocumentMovement::STATUS_RECEIVED
            && $receipt?->action === DocumentMovement::ACTION_RECEIVED
            && ($receipt->from_status === DocumentMovement::STATUS_FORWARDED
                || (in_array($receipt->from_status, [
                    DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
                    DocumentMovement::STATUS_AWAITING_RECEIPT,
                ], true) && $document->officeAssignments->count() === 1));
    }

    /**
     * @return array{office: string, status: string}|null
     */
    public function workflowState(User $user, Document $document, ?string $sourceOffice = null): ?array
    {
        if (filled($sourceOffice)) {
            return $this->workflowStates($user, $document)
                ->first(fn (array $state): bool => $state['office'] === $sourceOffice);
        }

        return $this->workflowStates($user, $document)->first();
    }

    /**
     * @return Collection<int, array{office: string, status: string}>
     */
    public function workflowStates(User $user, Document $document): Collection
    {
        if ($user->isReadOnlySupervisor()) {
            return collect();
        }

        if ($user->isAdministrator()) {
            return $document->assignedOfficeStatuses();
        }

        $offices = $user->assignedOfficeNames();

        if ($offices->isEmpty()) {
            return collect();
        }

        $assignments = $document->relationLoaded('officeAssignments')
            ? $document->officeAssignments
            : $document->officeAssignments()->get();

        $states = $assignments
            ->filter(fn (DocumentOfficeAssignment $assignment): bool => $offices->contains($assignment->office))
            ->map(fn (DocumentOfficeAssignment $assignment): array => [
                'office' => $assignment->office,
                'status' => $assignment->status,
            ]);

        if ($states->isEmpty() && $offices->contains($document->current_office)) {
            $states->push([
                'office' => $document->current_office,
                'status' => $document->status,
            ]);
        }

        return $states
            ->unique('office')
            ->values();
    }

    private function normalizeAction(string $action): string
    {
        return match ($action) {
            DocumentMovement::ACTION_ACKNOWLEDGE => DocumentMovement::ACTION_RECEIVED,
            DocumentMovement::ACTION_FORWARD => DocumentMovement::ACTION_FORWARDED,
            default => $action,
        };
    }

    /**
     * @param  array{office: string, status: string}  $state
     */
    private function ensureTransitionIsAllowed(User $user, Document $document, array $state, string $action): void
    {
        if (array_key_exists($action, $this->availableActionOptions($user, $document, $state))) {
            return;
        }

        throw ValidationException::withMessages([
            'workflow.action' => "This document cannot be {$this->pastTense($action)} while its status is ".StatusLabel::for($state['status']).'.',
        ]);
    }

    /**
     * @param  array{destination?: string|null, destinations?: list<string>}  $workflow
     * @return list<string>
     */
    private function destinations(array $workflow): array
    {
        $destinations = $workflow['destinations'] ?? [];

        if ($destinations === [] && filled($workflow['destination'] ?? null)) {
            $destinations = [(string) $workflow['destination']];
        }

        return collect($destinations)
            ->map(fn (string $office): string => trim($office))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $destinations
     * @return list<string>
     */
    private function validateDestinations(array $destinations, string $fromOffice, string $action): array
    {
        if ($destinations === []) {
            throw ValidationException::withMessages([
                'workflow.destinations' => 'Select at least one destination office.',
            ]);
        }

        if ($action !== DocumentMovement::ACTION_RESTORED && in_array($fromOffice, $destinations, true)) {
            throw ValidationException::withMessages([
                'workflow.destinations' => 'Select a different office for this routing update.',
            ]);
        }

        return $destinations;
    }

    private function statusForAction(string $action): string
    {
        return match ($action) {
            DocumentMovement::ACTION_SENT,
            DocumentMovement::ACTION_FORWARD,
            DocumentMovement::ACTION_FORWARDED => DocumentMovement::STATUS_FORWARDED,
            DocumentMovement::ACTION_RETURNED => DocumentMovement::STATUS_RETURNED,
            DocumentMovement::ACTION_RESUBMITTED => DocumentMovement::STATUS_RESUBMITTED,
            DocumentMovement::ACTION_ACKNOWLEDGE,
            DocumentMovement::ACTION_RECEIVED,
            DocumentMovement::ACTION_RESTORED => DocumentMovement::STATUS_RECEIVED,
            DocumentMovement::ACTION_REVIEWED => DocumentMovement::STATUS_REVIEWED,
            DocumentMovement::ACTION_APPROVED => DocumentMovement::STATUS_APPROVED,
            DocumentMovement::ACTION_REJECTED => DocumentMovement::STATUS_REJECTED,
            DocumentMovement::ACTION_ON_HOLD => DocumentMovement::STATUS_ON_HOLD,
            DocumentMovement::ACTION_RELEASED => DocumentMovement::STATUS_RELEASED,
            DocumentMovement::ACTION_COMPLETED => DocumentMovement::STATUS_COMPLETED,
            DocumentMovement::ACTION_ARCHIVE => DocumentMovement::STATUS_ARCHIVED,
        };
    }

    private function officeLabel(string|array|null $offices): string
    {
        if (is_array($offices)) {
            return collect($offices)->join(', ', ' and ');
        }

        return (string) $offices;
    }

    private function pastTense(string $action): string
    {
        return match ($action) {
            DocumentMovement::ACTION_RECEIVED => 'received',
            DocumentMovement::ACTION_SENT => 'sent',
            DocumentMovement::ACTION_FORWARDED => 'forwarded',
            DocumentMovement::ACTION_RETURNED => 'returned',
            DocumentMovement::ACTION_RESUBMITTED => 'resubmitted',
            DocumentMovement::ACTION_REVIEWED => 'reviewed',
            DocumentMovement::ACTION_APPROVED => 'approved',
            DocumentMovement::ACTION_REJECTED => 'rejected',
            DocumentMovement::ACTION_ON_HOLD => 'placed on hold',
            DocumentMovement::ACTION_RELEASED => 'released',
            DocumentMovement::ACTION_COMPLETED => 'completed',
            DocumentMovement::ACTION_ARCHIVE => 'archived',
            DocumentMovement::ACTION_RESTORED => 'restored',
            default => $action,
        };
    }
}
