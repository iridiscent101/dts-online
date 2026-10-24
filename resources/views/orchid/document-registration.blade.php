@push('head')
    <meta name="turbo-cache-control" content="no-cache">
@endpush

@php
    $documentTypes = \App\Models\Document::typeOptions();
    $officeOptions = \App\Models\Document::officeOptions();
    $originOptions = \App\Models\Document::originOptions();
    $selectedDestinations = old('document.destinations', ['']);
    $selectedRouteRemarks = old('document.route_remarks', []);
    $selectedDestinations = is_array($selectedDestinations) ? $selectedDestinations : [];
    $selectedRouteRemarks = is_array($selectedRouteRemarks) ? $selectedRouteRemarks : [];
@endphp

<dts-registration class="dts-workspace dts-registration" data-draft-user="{{ auth()->id() }}" data-has-old-input="{{ old('document') !== null ? 'true' : 'false' }}">
    <a class="dts-back" href="{{ route('platform.documents') }}"><x-orchid-icon path="bs.arrow-left" /> Document registry</a>
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <p class="dts-eyebrow">DOCUMENT REGISTRATION</p>
            <h2>New document</h2>
        </div>
        <span class="dts-status"><span class="dts-dot amber"></span>Not saved</span>
    </div>
    <p class="dts-preview-notice" data-draft-notice role="status" hidden></p>
    @if ($errors->any())
        <div class="dts-validation" role="alert">
            <strong>Please correct the highlighted information.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div>
        <div @class(['dts-registration-layout', 'dts-registration-layout-single' => ! config('app.document_uploads_enabled')])>
            <div class="tw:min-w-0">
                <section class="dts-form-section" aria-labelledby="document-information">
                    <div class="dts-section-heading"><h3 id="document-information"><x-orchid-icon path="bs.file-earmark-text" /> Document information</h3><span class="dts-required-note">* Required</span></div>
                    <div class="dts-form-grid">
                        <div class="dts-field dts-field-wide">
                            <label for="document-title">Document title <span aria-hidden="true">*</span></label>
                            <input id="document-title" name="document[title]" data-field="title" required maxlength="255" value="{{ old('document.title') }}" placeholder="Enter the document subject" autocomplete="off">
                        </div>
                        <div class="dts-field">
                            <label for="document-type">Document type <span aria-hidden="true">*</span></label>
                            <dts-searchable>
                                <input id="document-type" name="document[type]" data-field="type" data-searchable data-options="document-types" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="document-type-choices" value="{{ old('document.type') }}" placeholder="Search document types" autocomplete="off" required>
                                <div id="document-type-choices" data-suggestions role="listbox" hidden></div>
                            </dts-searchable>
                            <datalist id="document-types">
                                @foreach ($documentTypes as $type)
                                    <option value="{{ $type }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="dts-field dts-field-wide">
                            <label for="document-description">Description <span class="dts-optional">Optional</span></label>
                            <textarea id="document-description" name="document[description]" data-field="description" rows="3" maxlength="3000" placeholder="Document summary or additional context">{{ old('document.description') }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="dts-form-section" aria-labelledby="sender-information">
                    <div class="dts-section-heading"><h3 id="sender-information"><x-orchid-icon path="bs.person" /> Sender &amp; dates</h3></div>
                    <div class="dts-form-grid">
                        <div class="dts-field">
                            <label for="document-origin">Originating office / school <span aria-hidden="true">*</span></label>
                            <dts-searchable>
                                <input id="document-origin" name="document[origin]" data-field="origin" data-searchable data-options="origin-units" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="document-origin-choices" required maxlength="180" value="{{ old('document.origin') }}" placeholder="Search or enter office or school" autocomplete="off">
                                <div id="document-origin-choices" data-suggestions role="listbox" hidden></div>
                            </dts-searchable>
                            <datalist id="origin-units">
                                @foreach ($originOptions as $originOption)
                                    <option value="{{ $originOption }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="dts-field">
                            <label for="document-sender">Sender <span aria-hidden="true">*</span></label>
                            <input id="document-sender" name="document[sender]" data-field="sender" required maxlength="180" value="{{ old('document.sender') }}" placeholder="Full name" autocomplete="off">
                        </div>
                        {{-- Optional: the sender is emailed each time the document is routed to an office. --}}
                        <div class="dts-field">
                            <label for="document-client-email">Sender email <span class="dts-muted">(optional)</span></label>
                            <input id="document-client-email" name="document[client_email]" data-field="client_email" type="email" maxlength="255" value="{{ old('document.client_email') }}" placeholder="For routing updates" autocomplete="off" aria-describedby="document-client-email-hint">
                            <small id="document-client-email-hint" class="dts-muted">Tell the sender to check their Spam folder if the email is not in their Inbox.</small>
                        </div>
                        <div class="dts-field">
                            <label for="document-received">Date received <span aria-hidden="true">*</span></label>
                            <input id="document-received" name="document[received]" data-field="received" type="date" required value="{{ old('document.received', now()->timezone('Asia/Manila')->toDateString()) }}">
                        </div>
                    </div>
                </section>

                <section class="dts-form-section" aria-labelledby="document-routing">
                    <div class="dts-section-heading">
                        <h3 id="document-routing"><x-orchid-icon path="bs.signpost-split" /> Routing</h3>
                        <span class="dts-required-note">* Required</span>
                    </div>
                    <div class="dts-form-grid">
                        <div class="dts-field dts-field-wide dts-route-destinations">
                            <div class="dts-route-destination-heading">
                                <label>Initial destination offices <span aria-hidden="true">*</span></label>
                                <button type="button" class="dts-button dts-button-secondary" data-registration-add-route><x-orchid-icon path="bs.plus-lg" /> Add route</button>
                            </div>
                            <div class="dts-route-destination-list" data-registration-route-list>
                                @foreach ($selectedDestinations as $selectedDestination)
                                    <div class="dts-route-destination-row" data-registration-route>
                                        <span class="dts-route-destination-number" data-registration-route-number>{{ $loop->iteration }}</span>
                                        <strong class="dts-route-destination-title" data-registration-route-title>Route {{ $loop->iteration }}</strong>
                                        <div class="dts-route-destination-fields">
                                            <div class="dts-route-office-field">
                                                <label for="document-route-office-{{ $loop->index }}" data-registration-office-label>Destination office {{ $loop->iteration }}</label>
                                                <dts-searchable>
                                                    <input id="document-route-office-{{ $loop->index }}" name="document[destinations][]" data-registration-office data-searchable data-options="destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="document-route-office-{{ $loop->index }}-choices" value="{{ $selectedDestination }}" placeholder="Search offices" autocomplete="off" required>
                                                    <div id="document-route-office-{{ $loop->index }}-choices" data-suggestions role="listbox" hidden></div>
                                                </dts-searchable>
                                            </div>
                                            <div class="dts-route-remarks-field">
                                                <label for="document-route-remarks-{{ $loop->index }}" data-registration-remarks-label>Remarks for destination {{ $loop->iteration }} <span class="dts-optional">Optional</span></label>
                                                <textarea id="document-route-remarks-{{ $loop->index }}" name="document[route_remarks][{{ $loop->index }}]" data-registration-remarks rows="2" maxlength="2000" placeholder="Instructions for this office">{{ $selectedRouteRemarks[$loop->index] ?? '' }}</textarea>
                                            </div>
                                        </div>
                                        <button type="button" class="dts-icon-button" data-registration-remove-route aria-label="Remove destination {{ $loop->iteration }}" title="Remove destination"><x-orchid-icon path="bs.x-lg" /></button>
                                    </div>
                                @endforeach
                            </div>
                            <p class="dts-field-hint">Anyone with the tracking number can read remarks. Leave out phone numbers, addresses, ID numbers, and health or money details.</p>
                            <p class="dts-field-error" data-registration-count-error role="alert" hidden>Select at least one destination office.</p>
                            <datalist id="destination-offices">
                                @foreach ($officeOptions as $office)
                                    <option value="{{ $office }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                    </div>
                </section>
            </div>
            @if (config('app.document_uploads_enabled'))
            <aside class="dts-registration-aside">
                <section class="dts-form-section" aria-labelledby="attachment-heading">
                    <div class="dts-section-heading"><h3 id="attachment-heading">Attachments</h3><span class="dts-count" data-file-count>0 / 5</span></div>
                    <label class="dts-file-picker" for="document-files">
                        <x-orchid-icon path="bs.cloud-arrow-up" />
                        <strong>Choose files</strong>
                        <span>PDF, image, Word, Excel, or CSV &middot; 10 MB per file</span>
                        <input id="document-files" name="attachments[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.xls,.csv" aria-describedby="attachment-error">
                    </label>
                    <p id="attachment-error" class="dts-validation" role="status" aria-live="polite"></p>
                    <ul class="dts-attachment-list" data-file-list="edit" aria-label="Selected attachments"></ul>
                    <p class="dts-muted" data-no-files>No files selected.</p>
                </section>
            </aside>
            @endif
        </div>
        <div class="dts-form-actions dts-form-actions-grouped">
            <a class="dts-button" href="{{ route('platform.documents') }}" data-draft-discard>Cancel</a>
            <button type="submit" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.create').'/save' }}" data-confirm-message="Register this document?" data-confirm-title="Register document" data-confirm-label="Register"><x-orchid-icon path="bs.check-circle" /> Register document</button>
        </div>
    </div>

    @if (config('app.document_uploads_enabled'))
    <template data-file-template>
        <li class="dts-attachment">
            <span class="dts-icon"><x-orchid-icon path="bs.file-earmark" /></span>
            <div class="dts-attachment-info"><a data-file-link target="_blank" rel="noopener noreferrer"></a><small data-file-size></small></div>
            <button type="button" class="dts-icon-button" data-remove-file><x-orchid-icon path="bs.x-lg" /></button>
        </li>
    </template>
    @endif
    <template data-registration-route-template>
        <div class="dts-route-destination-row" data-registration-route>
            <span class="dts-route-destination-number" data-registration-route-number></span>
            <strong class="dts-route-destination-title" data-registration-route-title></strong>
            <div class="dts-route-destination-fields">
                <div class="dts-route-office-field">
                    <label data-registration-office-label></label>
                    <dts-searchable>
                        <input name="document[destinations][]" data-registration-office data-searchable data-options="destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off" required>
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                </div>
                <div class="dts-route-remarks-field">
                    <label data-registration-remarks-label></label>
                    <textarea data-registration-remarks rows="2" maxlength="2000" placeholder="Instructions for this office"></textarea>
                </div>
            </div>
            <button type="button" class="dts-icon-button" data-registration-remove-route title="Remove destination"><x-orchid-icon path="bs.x-lg" /></button>
        </div>
    </template>
</dts-registration>
