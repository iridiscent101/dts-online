@php
    // RouteFixActions is the only rule for these two: it returns null unless this user may really correct or add a
    // destination on this document. The tab no longer decides, so Incoming shows them too when they apply
    // (an administrator, or an office that both sent and receives the same document).
    ['correction' => $routeCorrection, 'additional' => $additionalRoute] = \App\Support\RouteFixActions::for($document, request()->user());
@endphp
{{-- Always in this order: Edit, Correct a destination, Add destination, Delete. The row is left-aligned, so
     Edit keeps the same place on every row. Correct and Add leave no empty slot when they do not apply: most rows
     have neither, and two blank squares between Edit and Delete read as a broken layout (user, 2026-11-08). --}}
<div class="dts-row-actions">
    @if ($canEdit)
        <a class="btn dts-row-action dts-row-action-edit" href="{{ $editUrl }}" data-workspace-url="{{ $workspaceUrl }}" data-workspace-edit-open data-tooltip="Edit"><x-orchid-icon path="bs.pencil" /> Edit</a>
    @elseif ($canDelete)
        <span class="dts-row-action-slot" aria-hidden="true"></span>
    @endif
    @if ($routeCorrection)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-route-correction='@json($routeCorrection)' data-tooltip="Correct a destination" aria-label="Correct a destination for {{ $document->tracking_number }}"><x-orchid-icon path="bs.arrow-left-right" /> Correct a destination</button>
    @endif
    @if ($additionalRoute || $routeCorrection)
        @include('orchid.partials.route-fix-offices')
    @endif
    @if ($additionalRoute)
        <button type="button" class="btn dts-row-action dts-row-action-send" data-additional-route='@json($additionalRoute)' data-tooltip="Add destination" aria-label="Add a destination for {{ $document->tracking_number }}"><x-orchid-icon path="bs.send-plus" /> Add destination</button>
    @endif
    @if ($canDelete)
        {{ $deleteAction }}
    @elseif ($canEdit)
        <span class="dts-row-action-slot" aria-hidden="true"></span>
    @endif
</div>
