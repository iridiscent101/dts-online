@php
    // Offices past "Awaiting receipt"/"Forwarded" have started work; the server refuses to remove them, so they are locked here.
    $startedOffices = isset($document)
        ? $document->officeAssignments
            ->whereNotIn('status', [\App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT, \App\Models\DocumentMovement::STATUS_FORWARDED])
            ->pluck('office')
            ->all()
        : [];
    // Offices another office forwarded the document to: the editor cannot remove them; Correct route replaces one.
    $forwardedOffices = isset($document)
        ? $document->officeAssignments->where('status', \App\Models\DocumentMovement::STATUS_FORWARDED)->pluck('office')->all()
        : [];
    // After processing starts the list cannot change here at all (Document::routeListIsLocked).
    $routeListLocked = isset($document) && $document->routeListIsLocked();
    // Finished offices (completed, released…) stay on the route but do not count toward the five active offices.
    $finishedOffices = isset($document)
        ? $document->officeAssignments
            ->whereIn('status', \App\Models\DocumentMovement::FINISHED_STATUSES)
            ->pluck('office')
            ->all()
        : [];
@endphp
@if ($routeListLocked)
    {{-- Read-only: no office fields are sent, so saving the details leaves the routes as they are. --}}
    <div class="dts-route-destinations dts-route-list-locked">
        <div class="dts-route-destination-heading"><label>Assigned offices</label></div>
        <p class="dts-route-locked-hint"><x-orchid-icon path="bs.lock-fill" /> Processing has started, so the assigned offices can no longer be changed here. Use <strong>Forward</strong> to send the document to another office, or <strong>Correct route</strong> to replace an office that has not received it yet.</p>
        <ul class="dts-route-locked-list">
            @foreach ($document->officeAssignments as $assignment)
                <li><span>{{ $assignment->office }}</span><span class="dts-record-status" data-status="{{ str($assignment->status)->lower()->replace(' ', '-') }}">{{ \App\Support\StatusLabel::for($assignment->status) }}</span></li>
            @endforeach
        </ul>
    </div>
@else
<dts-route-editor class="dts-route-destinations" data-initial-routes='@json(array_values($routeDestinations))'>
    <input type="hidden" name="document[route_fingerprint]" value="{{ $document->routeFingerprint() }}">
    <div class="dts-route-destination-heading">
        <label>Assigned offices <span aria-hidden="true">*</span></label>
        <button type="button" class="dts-button dts-button-secondary" data-edit-route-add><x-orchid-icon path="bs.plus-lg" /> Add office</button>
    </div>
    <p class="dts-field-hint">Changing the offices asks for a reason, which is kept in the history.</p>
    @if ($startedOffices !== [] || $forwardedOffices !== [])
        <p class="dts-route-locked-hint">Offices that have started work stay on the route and cannot be removed or changed.</p>
    @endif
    <div class="dts-route-destination-list" data-edit-route-list>
        @foreach ($routeDestinations as $routeIndex => $routeDestination)
            @php($routeForwarded = in_array($routeDestination, $forwardedOffices, true))
            @php($routeLocked = $routeForwarded || in_array($routeDestination, $startedOffices, true))
            @php($routeFinished = in_array($routeDestination, $finishedOffices, true))
            <div class="dts-route-destination-row" data-edit-route @if ($routeLocked) data-edit-route-locked @endif @if ($routeFinished) data-edit-route-finished @endif>
                <span class="dts-route-destination-number" data-edit-route-number>{{ $loop->iteration }}</span>
                <strong class="dts-route-destination-title" data-edit-route-title>Office {{ $loop->iteration }}</strong>
                <div class="dts-route-destination-fields">
                    <div class="dts-route-office-field">
                        <label for="document-edit-route-office-{{ $routeIndex }}" data-edit-office-label>Assigned office {{ $loop->iteration }}</label>
                        @if ($routeLocked)
                            <input id="document-edit-route-office-{{ $routeIndex }}" name="document[destinations][]" data-edit-office value="{{ $routeDestination }}" readonly aria-describedby="document-edit-route-office-{{ $routeIndex }}-note">
                            <p class="dts-route-locked-note" id="document-edit-route-office-{{ $routeIndex }}-note" data-edit-route-note><x-orchid-icon path="bs.lock-fill" /> {{ $routeFinished ? 'Finished, so it stays on the route. It does not count toward the five active offices.' : ($routeForwarded ? 'Forwarded by another office. Use Correct route to change it.' : 'Started work, so it cannot be removed.') }}</p>
                        @else
                            <dts-searchable>
                                <input id="document-edit-route-office-{{ $routeIndex }}" name="document[destinations][]" data-edit-office data-searchable data-options="edit-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="document-edit-route-office-{{ $routeIndex }}-choices" value="{{ $routeDestination }}" placeholder="Search offices" autocomplete="off" required>
                                <div id="document-edit-route-office-{{ $routeIndex }}-choices" data-suggestions role="listbox" hidden></div>
                            </dts-searchable>
                        @endif
                    </div>
                </div>
                @unless ($routeLocked)
                    <button type="button" class="dts-icon-button" data-edit-route-remove aria-label="Remove office {{ $loop->iteration }}" title="Remove office"><x-orchid-icon path="bs.x-lg" /></button>
                @endunless
            </div>
        @endforeach
    </div>
    <p class="dts-field-error" data-edit-route-count-error role="alert" hidden>Select at least one destination office.</p>
    <datalist id="edit-destination-offices">
        @foreach (\App\Models\Document::officeOptions() as $officeOption)
            <option value="{{ $officeOption }}"></option>
        @endforeach
    </datalist>
    <template data-edit-route-template>
        <div class="dts-route-destination-row" data-edit-route>
            <span class="dts-route-destination-number" data-edit-route-number></span>
            <strong class="dts-route-destination-title" data-edit-route-title></strong>
            <div class="dts-route-destination-fields">
                <div class="dts-route-office-field">
                    <label data-edit-office-label></label>
                    <dts-searchable>
                        <input name="document[destinations][]" data-edit-office data-searchable data-options="edit-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off" required>
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                </div>
            </div>
            <button type="button" class="dts-icon-button" data-edit-route-remove title="Remove office"><x-orchid-icon path="bs.x-lg" /></button>
        </div>
    </template>
</dts-route-editor>
@endif
