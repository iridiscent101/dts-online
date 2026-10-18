<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;

/**
 * The two fixes for a document an office already sent, shown on the Outgoing list and the dashboard queue:
 * "Correct route" swaps a destination chosen by mistake while that office has not received it yet
 * (Document::correctableRoutesFor), and "Send to another office" adds one more route while the original routes stay
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
                'tracking' => $document->tracking_number,
                'subject' => $document->subject,
                'routes' => $correctable,
                'assigned' => $document->officeAssignments->pluck('office')->values(),
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

        $finishedOffices = $document->officeAssignments
            ->whereIn('status', DocumentMovement::FINISHED_STATUSES)
            ->pluck('office');
        $sendingOffices = $sendingOffices->diff($finishedOffices)->values();

        return $sendingOffices->isEmpty() ? null : [
            'url' => route('platform.documents.additional-routes.store', $document),
            'tracking' => $document->tracking_number,
            'subject' => $document->subject,
            'sources' => $sendingOffices->values(),
            'assigned' => $document->officeAssignments->pluck('office')->values(),
        ];
    }
}
