@push('head')
    <meta name="turbo-cache-control" content="no-cache">
@endpush

<dts-registration class="dts-workspace dts-registration">
    <a class="dts-back" href="{{ route('platform.documents') }}"><x-orchid-icon path="bs.arrow-left" /> Document registry</a>
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <p class="dts-eyebrow">DOCUMENT REGISTRATION</p>
            <h2 data-page-heading tabindex="-1">New document</h2>
        </div>
        <span class="dts-status"><span class="dts-dot amber"></span>Not saved</span>
    </div>
    <p class="dts-preview-notice"><x-orchid-icon path="bs.shield-check" /> Attachments are stored privately and are available only to authorized staff.</p>
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
    <noscript><p class="dts-validation">Enable JavaScript to review this document.</p></noscript>
    <ol class="dts-steps" aria-label="Registration progress">
        <li data-step="edit" aria-current="step"><span>1</span>Document information</li>
        <li data-step="review"><span>2</span>Review document</li>
    </ol>

    <div data-edit-panel>
        <div class="dts-registration-layout">
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
                            <select id="document-type" name="document[type]" data-field="type" required>
                                <option value="">Select a type</option>
                                @foreach (['Memorandum', 'Letter', 'Report', 'Request', 'Endorsement', 'Other'] as $type)
                                    <option @selected(old('document.type') === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dts-field">
                            <label for="document-reference">External reference <span class="dts-optional">Optional</span></label>
                            <input id="document-reference" name="document[reference]" data-field="reference" maxlength="100" value="{{ old('document.reference') }}" placeholder="Reference from the originating office">
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
                            <input id="document-origin" name="document[origin]" data-field="origin" required maxlength="180" value="{{ old('document.origin') }}" placeholder="Office or school name">
                        </div>
                        <div class="dts-field">
                            <label for="document-sender">Sender <span aria-hidden="true">*</span></label>
                            <input id="document-sender" name="document[sender]" data-field="sender" required maxlength="180" value="{{ old('document.sender') }}" placeholder="Full name" autocomplete="off">
                        </div>
                        <div class="dts-field">
                            <label for="document-received">Date received <span aria-hidden="true">*</span></label>
                            <input id="document-received" name="document[received]" data-field="received" type="date" required value="{{ old('document.received', now()->timezone('Asia/Manila')->toDateString()) }}">
                        </div>
                        <div class="dts-field">
                            <label for="document-due">Due date <span class="dts-optional">Optional</span></label>
                            <input id="document-due" name="document[due]" data-field="due" type="date" value="{{ old('document.due') }}">
                        </div>
                    </div>
                </section>

                <section class="dts-form-section" aria-labelledby="initial-routing">
                    <div class="dts-section-heading"><h3 id="initial-routing"><x-orchid-icon path="bs.signpost-split" /> Initial routing</h3></div>
                    <div class="dts-form-grid">
                        <div class="dts-field">
                            <label for="document-destination">Destination office <span aria-hidden="true">*</span></label>
                            <input id="document-destination" name="document[destination]" data-field="destination" required maxlength="180" value="{{ old('document.destination') }}" placeholder="Receiving office or unit">
                        </div>
                        <div class="dts-field">
                            <label for="document-priority">Priority <span aria-hidden="true">*</span></label>
                            <select id="document-priority" name="document[priority]" data-field="priority" required>
                                @foreach (['Normal', 'High', 'Urgent'] as $priority)
                                    <option @selected(old('document.priority', 'Normal') === $priority)>{{ $priority }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dts-field dts-field-wide">
                            <label for="document-remarks">Routing remarks <span class="dts-optional">Optional</span></label>
                            <textarea id="document-remarks" name="document[remarks]" data-field="remarks" rows="3" maxlength="2000" placeholder="Requested action or instructions for the receiving office">{{ old('document.remarks') }}</textarea>
                        </div>
                    </div>
                </section>
            </div>
            <aside class="dts-registration-aside">
                <section class="dts-form-section" aria-labelledby="tracking-information">
                    <h3 id="tracking-information">Tracking number</h3>
                    <p class="dts-tracking-pending"><x-orchid-icon path="bs.upc-scan" /> Pending registration</p>
                    <p class="dts-muted">Assigned when the document is registered.</p>
                </section>
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
                <dl class="dts-metadata">
                    <dt>Registration status</dt><dd>Not registered</dd>
                    <dt>Current office</dt><dd>Not assigned</dd>
                </dl>
            </aside>
        </div>
        <div class="dts-form-actions dts-form-actions-grouped">
            <a class="dts-button" href="{{ route('platform.documents') }}">Cancel</a>
            <button type="button" class="dts-button dts-button-primary" data-dts-action="review" disabled>Review document <x-orchid-icon path="bs.arrow-right" /></button>
        </div>
    </div>

    <div data-review-panel hidden>
        <div class="dts-detail-summary">
            <span class="dts-empty-icon"><x-orchid-icon path="bs.file-earmark-text" /></span>
            <div class="tw:min-w-0"><p class="dts-eyebrow">PENDING REGISTRATION</p><h3 data-value="title"></h3><p><span data-value="type"></span><span aria-hidden="true"> &middot; </span><span data-value="priority"></span> priority</p></div>
        </div>
        <div class="dts-registration-layout">
            <div class="tw:min-w-0">
                <section class="dts-form-section" aria-labelledby="review-details">
                    <div class="dts-section-heading"><h3 id="review-details">Document details</h3><button class="dts-text-button" type="button" data-dts-action="edit"><x-orchid-icon path="bs.pencil" /> Edit</button></div>
                    <dl class="dts-detail-grid">
                        @foreach (['reference' => 'External reference', 'received' => 'Date received', 'origin' => 'Originating office / school', 'sender' => 'Sender', 'due' => 'Due date', 'destination' => 'Destination office'] as $key => $label)
                            <div><dt>{{ $label }}</dt><dd data-value="{{ $key }}"></dd></div>
                        @endforeach
                    </dl>
                    <dl class="dts-metadata"><dt>Description</dt><dd data-value="description"></dd><dt>Routing remarks</dt><dd data-value="remarks"></dd></dl>
                </section>
                <section class="dts-form-section" aria-labelledby="review-attachments">
                    <div class="dts-section-heading"><h3 id="review-attachments">Attachments</h3><span class="dts-count" data-review-file-count>0 files</span></div>
                    <ul class="dts-attachment-list" data-file-list="review" aria-label="Attachments for review"></ul>
                    <p class="dts-muted" data-review-no-files>No attachments selected.</p>
                </section>
                <section class="dts-form-section" aria-labelledby="movement-history">
                    <h3 id="movement-history">Movement timeline</h3>
                    <div class="dts-timeline-empty"><x-orchid-icon path="bs.clock-history" /><div><strong>No recorded movements</strong><p>This document has not been registered or forwarded.</p></div></div>
                </section>
            </div>
            <aside class="dts-registration-aside">
                <h3>Document status</h3>
                <dl class="dts-metadata"><dt>Status</dt><dd><span class="dts-status">Not registered</span></dd><dt>Tracking number</dt><dd>Pending registration</dd><dt>Current office</dt><dd>Not assigned</dd></dl>
                <div class="dts-route-preview"><x-orchid-icon path="bs.send" /><div><small>Planned destination</small><strong data-value="destination"></strong><span>Not sent</span></div></div>
            </aside>
        </div>
        <div class="dts-form-actions">
            <button type="button" class="dts-button" data-dts-action="edit"><x-orchid-icon path="bs.arrow-left" /> Back to editing</button>
            <button type="submit" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.create').'/save' }}"><x-orchid-icon path="bs.check-circle" /> Register document</button>
        </div>
    </div>

    <template data-file-template>
        <li class="dts-attachment">
            <span class="dts-icon"><x-orchid-icon path="bs.file-earmark" /></span>
            <div class="dts-attachment-info"><a data-file-link target="_blank" rel="noopener noreferrer"></a><small data-file-size></small></div>
            <button type="button" class="dts-icon-button" data-remove-file><x-orchid-icon path="bs.x-lg" /></button>
        </li>
    </template>
</dts-registration>
