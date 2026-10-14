<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentOfficeAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentRoutingService
{
    private const RECORDS_OFFICES = ['SDO-Records Unit', 'Records Unit'];

    /**
     * @param  array{action: string, source_office?: string|null, destination?: string|null, destinations?: list<string>, remarks?: string|null, destination_remarks?: list<string|null>}  $workflow
     * @return array{action: string, office: string, offices: list<string>, final_records_office?: string}
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
            $this->ensureTransitionIsAllowed($workflowState['status'], $action);

            if ($action === DocumentMovement::ACTION_COMPLETED
                && in_array($workflowState['office'], self::RECORDS_OFFICES, true)
                && $this->hasUnfinishedNonRecordsRoutes($currentDocument)) {
                throw ValidationException::withMessages([
                    'workflow.action' => 'Records Unit can complete the document after the other offices finish their routes.',
                ]);
            }

            if ($action !== DocumentMovement::ACTION_RECEIVED
                && $workflowState['status'] !== DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                && DocumentMovement::requiresAcknowledgement($workflowState['status'])) {
                $receipt = $currentDocument->movements()->create([
                    'action' => DocumentMovement::ACTION_RECEIVED,
                    'from_office' => $workflowState['office'],
                    'to_office' => $workflowState['office'],
                    'from_status' => $workflowState['status'],
                    'to_status' => DocumentMovement::STATUS_RECEIVED,
                    'remarks' => 'Automatically recorded when processing began.',
                    'acted_by' => $user->getAuthIdentifier(),
                ]);

                $currentDocument->officeAssignments()->updateOrCreate(
                    ['office' => $workflowState['office']],
                    [
                        'status' => DocumentMovement::STATUS_RECEIVED,
                        'assigned_by' => $user->getAuthIdentifier(),
                        'movement_id' => $receipt->getKey(),
                    ],
                );
                $workflowState['status'] = DocumentMovement::STATUS_RECEIVED;
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

                if ($alreadyAssigned !== null) {
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

            // When the last processing office finishes, Records Unit receives the final completion task.
            $remainingAssignment = null;
            $finalRecordsOffice = null;
            if (in_array($status, DocumentMovement::FINISHED_STATUSES, true)) {
                $assignments = $currentDocument->officeAssignments()->oldest('id');
                $processingAssignment = (clone $assignments)
                    ->whereNotIn('office', self::RECORDS_OFFICES)
                    ->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)
                    ->first();
                $remainingAssignment = $processingAssignment
                    ?? (clone $assignments)->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)->first();

                if ($processingAssignment === null && ! in_array($workflowState['office'], self::RECORDS_OFFICES, true)) {
                    $finalRecordsOffice = $currentDocument->officeAssignments
                        ->pluck('office')
                        ->first(fn (string $office): bool => in_array($office, self::RECORDS_OFFICES, true))
                        ?? self::RECORDS_OFFICES[0];
                    $finalMovement = $currentDocument->movements()->create([
                        'action' => DocumentMovement::ACTION_FORWARDED,
                        'from_office' => $workflowState['office'],
                        'to_office' => $finalRecordsOffice,
                        'from_status' => $status,
                        'to_status' => DocumentMovement::STATUS_FORWARDED,
                        'remarks' => 'For final completion by Records Unit.',
                        'acted_by' => $user->getAuthIdentifier(),
                    ]);
                    $remainingAssignment = $currentDocument->officeAssignments()->updateOrCreate(
                        ['office' => $finalRecordsOffice],
                        [
                            'status' => DocumentMovement::STATUS_FORWARDED,
                            'assigned_by' => $user->getAuthIdentifier(),
                            'movement_id' => $finalMovement->getKey(),
                        ],
                    );
                }
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
                'final_records_office' => $finalRecordsOffice,
            ], fn (mixed $value): bool => $value !== null);
        });
    }

    public function successMessage(Document $document, string $action, string|array|null $destination): string
    {
        $destinationLabel = $this->officeLabel($destination);

        return match ($action) {
            DocumentMovement::ACTION_ACKNOWLEDGE, DocumentMovement::ACTION_RECEIVED => "Receipt for {$document->tracking_number} was acknowledged.",
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
            return [DocumentMovement::ACTION_RECEIVED => 'Confirm paper received'];
        }

        $options = DocumentMovement::availableActionOptionsForStatus($state['status']);
        if (! $this->canRestore($user)) {
            unset($options[DocumentMovement::ACTION_RESTORED]);
        }
        if (in_array($state['office'], self::RECORDS_OFFICES, true) && $this->hasUnfinishedNonRecordsRoutes($document)) {
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

    private function ensureTransitionIsAllowed(string $status, string $action): void
    {
        if (in_array($action, DocumentMovement::availableActionsForStatus($status), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'workflow.action' => "This document cannot be {$this->pastTense($action)} while its status is {$status}.",
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
