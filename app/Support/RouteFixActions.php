<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentOfficeAssignment;
use App\Models\User;

/**
 * The two fixes for a document an office already sent, shown on All Documents, Outgoing, and the dashboard queue:
 * "Correct route" swaps a destination chosen by mistake while that office has not received it yet
 * (Document::correctableRoutesFor), and "Add office route" adds one more route while the original routes stay
 * as they are (DocumentRoutingService::addAdditionalRoute). Each is null when it does not apply; otherwise it holds the
 * data its dialog in dts.js needs.
 */
class RouteFixActions
{
    /**
     * @return array{correction: array<string, mixed>|null, additional: array<string, mixed>|null}
     */
    public static function for(Document $document, ?User $user): array
    {
        if (! $user) {
            return ['correction' => null, 'additional' => null];
        }

        $correction = null;
        $correctable = $document->correctableRoutesFor($user);
        if ($correctable->isNotEmpty()) {
            $correction = [
                'url' => route('platform.documents.routes.correct', $document),
                'deleteUrl' => route('platform.documents.routes.delete', $document),
                'tracking' => $document->tracking_number,
                'subject' => $document->subject,
                'routes' => $correctable,
                // Offices that finished their part can be chosen again, as with Forward.
                'assigned' => $document->officeAssignments->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)->pluck('office')->values(),
            ];
        }

        return ['correction' => $correction, 'additional' => self::additionalRoute($document, $user)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function additionalRoute(Document $document, User $user): ?array
    {
        if ($user->isReadOnlySupervisor() || $document->isClosedRecord()
            || $document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
            return null;
        }

        $activeRouteCount = $document->officeAssignments->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)->count();
        if ($activeRouteCount === 0 || $activeRouteCount >= 5) {
            return null;
        }

        // Staff send from their own office; an administrator from any office that actually forwarded the document
        // (DocumentRoutingService::addAdditionalRoute checks the same).
        $userOffices = $user->assignedOfficeNames();
        $isAdministrator = $user->isAdministrator();
        if ($userOffices->isEmpty() && ! $isAdministrator) {
            return null;
        }

        $sendingOffices = $document->relationLoaded('forwardMovements')
            ? $document->forwardMovements->pluck('from_office')
                ->when(! $isAdministrator, fn ($offices) => $offices->intersect($userOffices))
                ->unique()
                ->values()
            : $document->forwardMovements()
                ->when(! $isAdministrator, fn ($query) => $query->whereIn('from_office', $userOffices))
                ->distinct()
                ->pluck('from_office');

        // A document still in its registration routing has no forwards yet; its sender is the office that registered
        // it, which DocumentRoutingService::addAdditionalRoute accepts on the same terms: every destination is still
        // awaiting receipt. Read from the loaded assignments, not the movement history, so the registry stays at one
        // query for all rows (DepEdDashboardTest counts movement reads).
        if ($sendingOffices->isEmpty() && $document->registered_office !== null
            && ($isAdministrator || $userOffices->contains($document->registered_office))
            && $document->officeAssignments->isNotEmpty()
            && $document->officeAssignments->every(fn (DocumentOfficeAssignment $assignment): bool => $assignment->status === DocumentMovement::STATUS_AWAITING_RECEIPT)) {
            $sendingOffices = collect([$document->registered_office]);
        }

        $unavailableOffices = $document->officeAssignments
            ->filter(fn (DocumentOfficeAssignment $assignment): bool => in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true)
                || DocumentMovement::requiresReceipt($assignment->status))
            ->pluck('office');
        $sendingOffices = $sendingOffices->diff($unavailableOffices)->values();

        return $sendingOffices->isEmpty() ? null : [
            'url' => route('platform.documents.additional-routes.store', $document),
            'tracking' => $document->tracking_number,
            'subject' => $document->subject,
            'sources' => $sendingOffices->values(),
            // Offices that finished their part can be added again, so only those still working on it are refused.
            'assigned' => $document->officeAssignments->whereNotIn('status', DocumentMovement::FINISHED_STATUSES)->pluck('office')->values(),
        ];
    }
}
