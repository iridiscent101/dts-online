@php
    // Offices past "Awaiting receipt"/"Forwarded" have started work; the server refuses to remove them, so they are locked here.
    $startedOffices = isset($document)
        ? $document->officeAssignments
            ->whereNotIn('status', [\App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT, \App\Models\DocumentMovement::STATUS_FORWARDED])
            ->pluck('office')
            ->all()
        : [];
@endphp
<dts-route-editor class="dts-route-destinations">
    <input type="hidden" name="document[route_fingerprint]" value="{{ $document->routeFingerprint() }}">
    <div class="dts-route-destination-heading">
        <label>Assigned offices <span aria-hidden="true">*</span></label>
        <button type="button" class="dts-button dts-button-secondary" data-edit-route-add><x-orchid-icon path="bs.plus-lg" /> Add office</button>
    </div>
    @if ($startedOffices !== [])
        <p class="dts-route-locked-hint">Offices that have started work stay on the route and cannot be removed or changed.</p>
    @endif
    <div class="dts-route-destination-list" data-edit-route-list>
        @foreach ($routeDestinations as $routeIndex => $routeDestination)
            @php($routeLocked = in_array($routeDestination, $startedOffices, true))
            <div class="dts-route-destination-row" data-edit-route @if ($routeLocked) data-edit-route-locked @endif>
                <span class="dts-route-destination-number" data-edit-route-number>{{ $loop->iteration }}</span>
                <strong class="dts-route-destination-title" data-edit-route-title>Office {{ $loop->iteration }}</strong>
                <div class="dts-route-destination-fields">
                    <div class="dts-route-office-field">
                        <label for="document-edit-route-office-{{ $routeIndex }}" data-edit-office-label>Assigned office {{ $loop->iteration }}</label>
                        @if ($routeLocked)
                            <input id="document-edit-route-office-{{ $routeIndex }}" name="document[destinations][]" data-edit-office value="{{ $routeDestination }}" readonly aria-describedby="document-edit-route-office-{{ $routeIndex }}-note">
                            <p class="dts-route-locked-note" id="document-edit-route-office-{{ $routeIndex }}-note" data-edit-route-note><x-orchid-icon path="bs.lock-fill" /> Started work, so it cannot be removed.</p>
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
