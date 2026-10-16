@php
    // Outgoing list: "Send to another office" adds one more route to an active document the user's office already
    // forwarded, while the original routes stay as they are (DocumentRoutingService::addAdditionalRoute).
    $additionalRoute = null;
    $rowUser = request()->user();
    if (request()->routeIs('platform.outgoing') && $rowUser && ! $rowUser->isReadOnlySupervisor()
        && ! $document->isClosedRecord() && $document->status !== \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
        $activeRouteCount = $document->officeAssignments->whereNotIn('status', \App\Models\DocumentMovement::FINISHED_STATUSES)->count();
        $userOffices = $rowUser->assignedOfficeNames();
        // reorder(): the movements relation sorts by date, and MySQL refuses ORDER BY on a column a DISTINCT list leaves out.
        $sendingOffices = $userOffices->isEmpty() ? collect() : $document->movements()
            ->reorder()
            ->whereIn('action', [\App\Models\DocumentMovement::ACTION_FORWARD, \App\Models\DocumentMovement::ACTION_FORWARDED])
            ->whereIn('from_office', $userOffices)
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
@endphp
<div class="dts-row-actions">
    @if ($additionalRoute)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-additional-route='@json($additionalRoute)' data-tooltip="Send to another office" aria-label="Send {{ $document->tracking_number }} to another office"><x-orchid-icon path="bs.send-plus" /> Send to another office</button>
        @once
            {{-- Offices for the "Send to another office" dialog (dts.js), listed once per page. --}}
            <datalist id="additional-route-offices">
                @foreach (\App\Models\Document::officeOptions() as $officeOption)
                    <option value="{{ $officeOption }}"></option>
                @endforeach
            </datalist>
        @endonce
    @endif
    @if ($canEdit)
        <a class="btn dts-row-action dts-row-action-edit" href="{{ $editUrl }}" data-workspace-url="{{ $workspaceUrl }}" data-workspace-edit-open data-tooltip="Edit"><x-orchid-icon path="bs.pencil" /> Edit</a>
    @endif
    @if ($canDelete)
        {{ $deleteAction }}
    @endif
</div>
