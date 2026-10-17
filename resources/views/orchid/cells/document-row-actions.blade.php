@php
    // Outgoing list: "Send to another office" adds one more route to an active document the user's office already
    // forwarded, while the original routes stay as they are (DocumentRoutingService::addAdditionalRoute).
    $additionalRoute = null;
    $rowUser = request()->user();
    if (request()->routeIs('platform.outgoing') && $rowUser && ! $rowUser->isReadOnlySupervisor()
        && ! $document->isClosedRecord() && $document->status !== \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
        $activeRouteCount = $document->officeAssignments->whereNotIn('status', \App\Models\DocumentMovement::FINISHED_STATUSES)->count();
        $userOffices = $rowUser->assignedOfficeNames();
        // Staff send from their own office; an administrator from any office that actually forwarded the document
        // (DocumentRoutingService::addAdditionalRoute checks the same).
        $isAdministrator = $rowUser->isAdministrator();
        // reorder(): the movements relation sorts by date, and MySQL refuses ORDER BY on a column a DISTINCT list leaves out.
        $sendingOffices = $userOffices->isEmpty() && ! $isAdministrator ? collect() : $document->movements()
            ->reorder()
            ->whereIn('action', [\App\Models\DocumentMovement::ACTION_FORWARD, \App\Models\DocumentMovement::ACTION_FORWARDED])
            ->whereNotNull('from_office')
            ->when(! $isAdministrator, fn ($query) => $query->whereIn('from_office', $userOffices))
            ->distinct()
            ->pluck('from_office');
        if ($sendingOffices->isNotEmpty() && $activeRouteCount > 0 && $activeRouteCount < 5) {
            $additionalRoute = [
                'url' => route('platform.documents.additional-routes.store', $document),
                'tracking' => $document->tracking_number,
                'subject' => $document->subject,
                'sources' => $sendingOffices->values(),
                'assigned' => $document->officeAssignments->pluck('office')->values(),
            ];
        }
    }

    // Outgoing list: "Correct route" swaps a destination the user's office chose by mistake for the right office,
    // while that office has not received it yet (Document::correctableRoutesFor).
    $routeCorrection = null;
    if (request()->routeIs('platform.outgoing') && $rowUser) {
        $correctable = $document->correctableRoutesFor($rowUser);
        if ($correctable->isNotEmpty()) {
            $routeCorrection = [
                'url' => route('platform.documents.routes.correct', $document),
                'tracking' => $document->tracking_number,
                'subject' => $document->subject,
                'routes' => $correctable,
                'assigned' => $document->officeAssignments->pluck('office')->values(),
            ];
        }
    }
@endphp
<div class="dts-row-actions">
    @if ($routeCorrection)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-route-correction='@json($routeCorrection)' data-tooltip="Change an office that has not received it yet" aria-label="Correct the route of {{ $document->tracking_number }}"><x-orchid-icon path="bs.arrow-left-right" /> Correct route</button>
    @endif
    @if ($additionalRoute || $routeCorrection)
        @once
            {{-- Offices for the "Send to another office" and "Correct route" dialogs (dts.js), listed once per page. --}}
            <datalist id="additional-route-offices">
                @foreach (\App\Models\Document::officeOptions() as $officeOption)
                    <option value="{{ $officeOption }}"></option>
                @endforeach
            </datalist>
        @endonce
    @endif
    @if ($additionalRoute)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-additional-route='@json($additionalRoute)' data-tooltip="Send to another office" aria-label="Send {{ $document->tracking_number }} to another office"><x-orchid-icon path="bs.send-plus" /> Send to another office</button>
    @endif
    @if ($canEdit)
        <a class="btn dts-row-action dts-row-action-edit" href="{{ $editUrl }}" data-workspace-url="{{ $workspaceUrl }}" data-workspace-edit-open data-tooltip="Edit"><x-orchid-icon path="bs.pencil" /> Edit</a>
    @endif
    @if ($canDelete)
        {{ $deleteAction }}
    @endif
</div>
