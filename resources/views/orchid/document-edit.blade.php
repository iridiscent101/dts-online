<div class="dts-workspace dts-registration dts-document-edit">
    {{-- Stale-edit check: the server refuses the save if the details changed after this form was loaded.
         Separate from the route fingerprint in the route editor. --}}
    <input type="hidden" name="document[details_fingerprint]" value="{{ $document->detailsFingerprint() }}">
    <a class="dts-back" href="{{ route('platform.documents.view', $document) }}"><x-orchid-icon path="bs.arrow-left" /> {{ $document->tracking_number }}</a>

    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <h2>Edit document</h2>
        <span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($document->status) }}>{{ \App\Support\StatusLabel::for($document->status) }}</span>
    </div>

    @php
        // Locked details open read-only; after a refused save that already had a reason, they stay unlocked.
        $detailsLocked = $document->detailsRequireUnlockFor(request()->user());
        $correctionReason = (string) old('document.correction_reason', '');
        $fieldsLocked = $detailsLocked && $correctionReason === '';
    @endphp
    @if ($detailsLocked)
        {{-- "Unlock details" (dts.js) asks for the reason and frees the fields; the server keeps the reason in the history. --}}
        <section class="dts-form-section dts-correction-section">
            <div class="dts-details-lock" data-details-lock @if (! $fieldsLocked) hidden @endif>
                <button type="button" class="dts-button dts-button-secondary" data-unlock-details><x-orchid-icon path="bs.unlock" /> Unlock details</button>
            </div>
            <input type="hidden" name="document[correction_reason]" value="{{ $correctionReason }}" data-correction-reason>
            <p class="dts-correction-note" data-correction-note @if ($fieldsLocked) hidden @endif>Unlocked for correction. Reason: {{ $correctionReason }}</p>
        </section>
    @endif

    @if ($errors->any())
        <div class="dts-validation" role="alert">
            <strong>Please correct the highlighted information.</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="tw:grid tw:grid-cols-1 tw:items-start tw:gap-4 tw:xl:grid-cols-2">
        <section class="dts-form-section" aria-labelledby="edit-document-information">
            <div class="dts-section-heading"><h3 id="edit-document-information"><x-orchid-icon path="bs.file-earmark-text" /> Document details</h3><span class="dts-required-note">* Required</span></div>
            <div class="dts-form-grid" @if ($fieldsLocked) data-locked-fields @endif>
                <div class="dts-field dts-field-wide">
                    <label for="document-title">Document title <span aria-hidden="true">*</span></label>
                    <input id="document-title" name="document[title]" required maxlength="255" value="{{ old('document.title', $document->subject) }}" @readonly($fieldsLocked) autocomplete="off">
                </div>
                <div class="dts-field">
                    <label for="document-type">Document type <span aria-hidden="true">*</span></label>
                    <dts-searchable>
                        <input id="document-type" name="document[type]" data-searchable data-options="document-types" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ old('document.type', $document->document_type) }}" @readonly($fieldsLocked) placeholder="Search document types" autocomplete="off" required>
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                    <datalist id="document-types">
                        @if (! in_array($document->document_type, \App\Models\Document::typeOptions(), true))
                            <option value="{{ $document->document_type }}"></option>
                        @endif
                        @foreach (\App\Models\Document::typeOptions() as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="dts-field">
                    <label for="document-origin">Originating office / school <span aria-hidden="true">*</span></label>
                    <dts-searchable>
                        <input id="document-origin" name="document[origin]" data-searchable data-options="origin-units" role="combobox" aria-autocomplete="list" aria-expanded="false" required maxlength="180" value="{{ old('document.origin', $document->origin) }}" @readonly($fieldsLocked) placeholder="Search or enter office or school" autocomplete="off">
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                    <datalist id="origin-units">
                        @foreach (\App\Models\Document::originOptions() as $originOption)
                            <option value="{{ $originOption }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="dts-field">
                    <label for="document-sender">Sender <span aria-hidden="true">*</span></label>
                    <input id="document-sender" name="document[sender]" required maxlength="180" value="{{ old('document.sender', $document->sender) }}" @readonly($fieldsLocked) autocomplete="off">
                </div>
                {{-- Contact only, so it stays editable after the details lock (a typo can be fixed without unlocking). --}}
                <div class="dts-field">
                    <label for="document-client-email">Sender email <span class="dts-muted">(optional)</span></label>
                    <input id="document-client-email" name="document[client_email]" type="email" maxlength="255" value="{{ old('document.client_email', $document->client_email) }}" placeholder="For routing updates" autocomplete="off" aria-describedby="document-client-email-hint">
                    <small id="document-client-email-hint" class="dts-field-hint">The sender is emailed each time the document is routed to an office.</small>
                </div>
                <div class="dts-field">
                    <label for="document-received">Date received @if ($document->received_at !== null)<span aria-hidden="true">*</span>@endif</label>
                    <input id="document-received" name="document[received]" type="date" @required($document->received_at !== null) value="{{ old('document.received', $document->received_at?->toDateString()) }}" @readonly($fieldsLocked) @disabled($document->received_at === null) @if ($document->received_at === null) aria-describedby="document-dates-pending" @endif>
                    @if ($document->received_at === null)
                        <small id="document-dates-pending" class="dts-field-hint">Not received yet. Set when the Records Unit receives the document/s.</small>
                    @endif
                </div>
            </div>
        </section>

        <details class="dts-form-section" @if ($errors->has('document.reference') || $errors->has('document.description')) open @endif>
            <summary class="dts-section-heading tw:cursor-pointer tw:list-none">
                <h3><x-orchid-icon path="bs.info-circle" /> Optional information</h3>
            </summary>
            <div class="dts-form-grid" @if ($fieldsLocked) data-locked-fields @endif>
                <div class="dts-field">
                    <label for="document-reference">External reference</label>
                    <input id="document-reference" name="document[reference]" maxlength="100" value="{{ old('document.reference', $document->external_reference) }}" @readonly($fieldsLocked)>
                </div>
                <div class="dts-field dts-field-wide">
                    <label for="document-description">Description</label>
                    <textarea id="document-description" name="document[description]" rows="3" maxlength="3000" @readonly($fieldsLocked)>{{ old('document.description', $document->description) }}</textarea>
                </div>
            </div>
        </details>

        @php
            $routeEditorUser = request()->user();
            $canManageRoutes = $routeEditorUser !== null && $document->canEditOfficeAssignmentsBy($routeEditorUser);
            $routesAreEditable = $canManageRoutes && $document->routesAreEditable();
            $assignedOffices = $document->officeAssignments()->pluck('office')->all();
            $routeDestinations = old('document.destinations', $assignedOffices);
            $routeDestinations = is_array($routeDestinations) ? $routeDestinations : $assignedOffices;
        @endphp

        @if ($canManageRoutes)
            <details class="dts-form-section tw:xl:col-span-2" aria-labelledby="edit-routing" @if ($errors->has('document.destinations') || $errors->has('document.destinations.*')) open @endif>
                    <summary class="dts-section-heading tw:cursor-pointer tw:list-none">
                        <h3 id="edit-routing"><x-orchid-icon path="bs.signpost-split" /> Routing</h3>
                        @if ($routesAreEditable)
                            <span class="dts-required-note">* Required</span>
                        @else
                            <span class="dts-optional">Locked</span>
                        @endif
                    </summary>

                    @if ($routesAreEditable)
                        @include('orchid.partials.document-route-editor', ['routeDestinations' => $routeDestinations])
                    @else
                        <p class="dts-muted">Routing is locked while the document status is {{ \App\Support\StatusLabel::for($document->status) }}.</p>
                        <ol class="dts-review-route-list" aria-label="Assigned offices">
                            @foreach ($assignedOffices as $assignedOffice)
                                <li><strong>{{ $assignedOffice }}</strong></li>
                            @endforeach
                        </ol>
                    @endif
            </details>
        @endif
    </div>

    <div class="dts-form-actions dts-form-actions-grouped">
        <a class="dts-button" href="{{ route('platform.documents.view', $document) }}">Cancel</a>
        <button type="submit" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.edit', $document).'/save' }}"><x-orchid-icon path="bs.check-circle" /> Save changes</button>
    </div>
</div>
