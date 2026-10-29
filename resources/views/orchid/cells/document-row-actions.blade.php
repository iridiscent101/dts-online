@php
    // Outgoing list only: Correct route and Add office route (App\Support\RouteFixActions); the dashboard queue
    // shows the same two fixes as icons.
    ['correction' => $routeCorrection, 'additional' => $additionalRoute] = request()->routeIs('platform.outgoing')
        ? \App\Support\RouteFixActions::for($document, request()->user())
        : ['correction' => null, 'additional' => null];
@endphp
<div class="dts-row-actions">
    @if ($routeCorrection)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-route-correction='@json($routeCorrection)' data-tooltip="Change an office that has not received it yet" aria-label="Correct the route of {{ $document->tracking_number }}"><x-orchid-icon path="bs.arrow-left-right" /> Correct route</button>
    @endif
    @if ($additionalRoute || $routeCorrection)
        @include('orchid.partials.route-fix-offices')
    @endif
    @if ($additionalRoute)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-additional-route='@json($additionalRoute)' data-tooltip="Add office route" aria-label="Add an office route for {{ $document->tracking_number }}"><x-orchid-icon path="bs.send-plus" /> Add office route</button>
    @endif
    @if ($canEdit)
        <a class="btn dts-row-action dts-row-action-edit" href="{{ $editUrl }}" data-workspace-url="{{ $workspaceUrl }}" data-workspace-edit-open data-tooltip="Edit"><x-orchid-icon path="bs.pencil" /> Edit</a>
    @endif
    @if ($canDelete)
        {{ $deleteAction }}
    @endif
</div>
