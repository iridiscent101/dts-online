@php
    // Everyday actions the backend offers this user's offices; Restore (reopening a finished document) is not one.
    $everydayActions = collect($workflowActionOptions)->flatMap(fn (array $actions): array => $actions)
        ->except(\App\Models\DocumentMovement::ACTION_RESTORED);
    $canRestore = collect($workflowActionOptions)->contains(fn (array $actions): bool => array_key_exists(\App\Models\DocumentMovement::ACTION_RESTORED, $actions));
    $nextStep = $everydayActions->has(\App\Models\DocumentMovement::ACTION_RECEIVED)
        ? 'Receive it first. After that you can forward or complete it.'
        : 'Next: '.$everydayActions->values()->join(', ', ' or ').'.';
@endphp
<div class="dts-workspace-shell" data-workspace-shell data-workspace-url="{{ route('platform.documents.workspace', $document) }}">
    <header class="dts-workspace-header">
        <div class="tw:min-w-0">
            <p class="dts-eyebrow dts-tracking-eyebrow">DOCUMENT WORKSPACE · {{ $document->tracking_number }} @include('orchid.partials.copy-button', ['text' => $document->tracking_number])</p>
            <div class="dts-workspace-title-row">
                <h2 id="dts-workspace-title" class="tw:break-words">{{ $document->subject }}</h2>
                <span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($document->status) }}>{{ \App\Support\StatusLabel::for($document->status) }}</span>
            </div>
        </div>
        {{-- Print label is always here, not only right after Paper received. --}}
        <div class="dts-workspace-header-actions">
            <a class="dts-button dts-button-secondary dts-icon-only" href="{{ route('platform.documents.label', $document) }}" target="_blank" rel="noopener" data-turbo="false" data-tooltip="Print label"><x-orchid-icon path="bs.printer" /><span class="visually-hidden">Print label</span></a>
            <button type="button" class="dts-workspace-close" data-workspace-close aria-label="Close document workspace">×</button>
        </div>
    </header>

    <div class="dts-workspace-tabs" role="tablist" aria-label="Document sections">
        <button type="button" id="dts-workspace-details-tab" role="tab" aria-selected="true" aria-controls="dts-workspace-details" tabindex="0" data-workspace-tab="details">Details</button>
        <button type="button" id="dts-workspace-routing-tab" role="tab" aria-selected="false" aria-controls="dts-workspace-routing" tabindex="-1" data-workspace-tab="routing">Routing &amp; History @if ($everydayActions->isNotEmpty())<span class="dts-tab-badge">Action needed</span>@endif</button>
    </div>

    <div id="dts-workspace-details" class="dts-workspace-panels dts-workspace-panels-single" role="tabpanel" aria-labelledby="dts-workspace-details-tab" data-workspace-panel="details">
        <section class="dts-workspace-panel" aria-labelledby="dts-workspace-details-heading">
            @if ($canRoute)
                {{-- The routing form lives on the other tab; this makes the next step visible. It scrolls with the details. --}}
                <div class="dts-workspace-next">
                    <x-orchid-icon path="bs.signpost-split" />
                    @if ($everydayActions->isNotEmpty())
                        <p><strong>Your office can act on this document.</strong> {{ $nextStep }}</p>
                        <button type="button" class="dts-button dts-button-primary" data-workspace-take-action>Take action <x-orchid-icon path="bs.arrow-right" /></button>
                    @elseif ($canRestore)
                        <p><strong>This document is finished.</strong> You can restore it if it needs more work.</p>
                        <button type="button" class="dts-button dts-button-secondary" data-workspace-take-action>Restore options <x-orchid-icon path="bs.arrow-right" /></button>
                    @endif
                </div>
            @endif
            <div class="dts-workspace-section-heading">
                <div><p class="dts-eyebrow">RECORD</p><h3 id="dts-workspace-details-heading">Document details</h3></div>
                @if ($document->canBeEditedBy(request()->user()))
                    @if ($document->isClosedRecord())
                        {{-- Finished record: only the details can change, so the administrator gives the reason (asked in dts.js) first. --}}
                        <button type="button" class="dts-button dts-button-secondary" data-workspace-edit data-workspace-unlock><x-orchid-icon path="bs.unlock" /> Unlock to edit</button>
                    @else
                        <button type="button" class="dts-button dts-button-secondary" data-workspace-edit>Edit document</button>
                    @endif
                @endif
            </div>
            {{-- Only closed records explain the lock here: staff get no Unlock button, and the Unlock prompt explains it to anyone who does. --}}
            @if ($document->isClosedRecord())
                <p class="dts-locked-note"><x-orchid-icon path="bs.lock-fill" /> This document is {{ strtolower($document->status) }}, so its details are locked.@if ($document->canBeEditedBy(request()->user())) To correct a mistake, unlock them and give a reason; the reason is kept in the history.@endif</p>
            @endif
            <div data-workspace-details-view>
            <dl class="dts-workspace-facts">
                @foreach ([
                    'Tracking number' => $document->tracking_number,
                    'Document type' => $document->document_type,
                    'Originating office' => $document->origin,
                    'Sender' => $document->sender,
                    'Sender email' => $document->client_email,
                ] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>
                @endforeach
                @if (filled($document->delivery_method))
                    <div><dt>Mode of submission</dt><dd>{{ $document->delivery_method }}</dd></div>
                @endif
                <div>
                    <dt>Date received</dt>
                    <dd>{{ $document->received_at?->format('M d, Y') ?? 'Not received' }}</dd>
                </div>
                @foreach ([
                    'Time registered' => $document->created_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
                    'Status' => $document->status,
                    // Who took the document in: the registering staff member, or the Records receiver of an online submission.
                    'Received by' => $document->client_submission_key ? ($document->recordsReceipt?->actor?->name ?? 'Not received yet') : $document->registeredBy?->name,
                    // Who the document was released to: the name the recipient gave when signing at the counter.
                    ...($document->status === \App\Models\DocumentMovement::STATUS_RELEASED && $document->latestMovement?->action === \App\Models\DocumentMovement::ACTION_RELEASED
                        ? ['Released to' => $document->latestMovement->released_to ?? 'Not recorded']
                        : []),
                ] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>
                @endforeach
            </dl>
            @if (filled($document->description))
                <div class="dts-workspace-description"><h4>Description</h4><p>{{ $document->description }}</p></div>
            @endif
            </div>
            @if ($document->canBeEditedBy(request()->user()))
                @php($detailsLocked = $document->detailsRequireUnlockFor(request()->user()))
                <div class="dts-workspace-editor" data-workspace-editor data-save-url="{{ route('platform.documents.edit', $document).'/save' }}" hidden>
                    <div class="dts-workspace-form-errors" data-workspace-form-errors role="alert" hidden></div>
                    <p class="dts-required-note dts-editor-required-note">Fields marked * are required.</p>
                    @if ($detailsLocked)
                        {{-- The detail fields below start read-only; "Unlock details" (dts.js) asks for the reason and frees them. --}}
                        <div class="dts-details-lock" data-details-lock>
                            <button type="button" class="dts-button dts-button-secondary" data-unlock-details><x-orchid-icon path="bs.unlock" /> Unlock details</button>
                        </div>
                        <input type="hidden" name="document[correction_reason]" value="" data-correction-reason>
                        <p class="dts-correction-note" data-correction-note hidden></p>
                    @endif
                    {{-- Stale-edit check: the server refuses the save if the details changed after this form was loaded.
                         Separate from the route fingerprint in the route editor. --}}
                    <input type="hidden" name="document[details_fingerprint]" value="{{ $document->detailsFingerprint() }}">
                    <input type="hidden" name="document[reference]" value="{{ $document->external_reference }}">
                    <div class="dts-workspace-fields" @if ($detailsLocked) data-locked-fields @endif>
                        <label><span>Document title <span aria-hidden="true">*</span></span><input name="document[title]" value="{{ $document->subject }}" required maxlength="255" @readonly($detailsLocked)></label>
                        <div class="dts-workspace-field"><label for="workspace-document-type">Document type <span aria-hidden="true">*</span></label>
                            <dts-searchable>
                                <input id="workspace-document-type" name="document[type]" data-searchable data-options="workspace-document-types" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $document->document_type }}" placeholder="Search document types" autocomplete="off" required @readonly($detailsLocked)>
                                <div data-suggestions role="listbox" hidden></div>
                            </dts-searchable>
                            <datalist id="workspace-document-types">@foreach (array_unique([...\App\Models\Document::typeOptions(), $document->document_type]) as $type)<option value="{{ $type }}"></option>@endforeach</datalist>
                        </div>
                        <div class="dts-workspace-field"><label for="workspace-document-origin">Originating office / school <span aria-hidden="true">*</span></label>
                            <dts-searchable>
                                <input id="workspace-document-origin" name="document[origin]" data-searchable data-options="workspace-origin-units" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $document->origin }}" placeholder="Search or enter office or school" autocomplete="off" required maxlength="180" @readonly($detailsLocked)>
                                <div data-suggestions role="listbox" hidden></div>
                            </dts-searchable>
                            <datalist id="workspace-origin-units">@foreach (\App\Models\Document::originOptions() as $originOption)<option value="{{ $originOption }}"></option>@endforeach</datalist>
                        </div>
                        <label><span>Sender <span aria-hidden="true">*</span></span><input name="document[sender]" value="{{ $document->sender }}" required maxlength="180" @readonly($detailsLocked)></label>
                        {{-- Contact only: editable even while the details are locked. --}}
                        <label><span>Sender email (optional)</span><input type="email" name="document[client_email]" value="{{ $document->client_email }}" maxlength="255" placeholder="For routing updates" autocomplete="off"></label>
                        {{-- A paper submission has no received date until Records confirms the paper copy. --}}
                        <label><span>Date received @if ($document->received_at !== null)<span aria-hidden="true">*</span>@endif</span><input type="date" name="document[received]" value="{{ $document->received_at?->toDateString() }}" @readonly($detailsLocked) @required($document->received_at !== null) @disabled($document->received_at === null) @if ($document->received_at === null) aria-describedby="workspace-dates-pending" @endif></label>
                        @if ($document->received_at === null)
                            <p id="workspace-dates-pending" class="dts-field-hint dts-workspace-field-wide">Not received yet. The date is set when the Records Unit receives the document/s.</p>
                        @endif
                        <label class="dts-workspace-field-wide">Description<textarea name="document[description]" rows="4" maxlength="3000" @readonly($detailsLocked)>{{ $document->description }}</textarea></label>
                    </div>
                    @if ($document->canEditOfficeAssignmentsBy(request()->user()) && $document->routesAreEditable())
                        <div class="dts-workspace-fields">
                            <div class="dts-workspace-field-wide">
                                @include('orchid.partials.document-route-editor', ['routeDestinations' => $document->officeAssignments->pluck('office')->all()])
                            </div>
                        </div>
                    @endif
                    <div class="dts-workspace-actions"><button type="button" class="dts-button" data-workspace-edit-cancel>Cancel</button><button type="button" class="dts-button dts-button-primary" data-workspace-details-save>Save changes</button></div>
                </div>
            @endif
        </section>
    </div>

    <div id="dts-workspace-routing" class="dts-workspace-panels" role="tabpanel" aria-labelledby="dts-workspace-routing-tab" data-workspace-panel="routing" hidden>
        <section class="dts-workspace-panel" aria-labelledby="dts-workspace-routes-heading">
            <div class="dts-workspace-section-heading"><div><p class="dts-eyebrow">WORKFLOW</p><h3 id="dts-workspace-routes-heading">Office routes</h3></div>@php($waitingOffices = $document->pendingRouteOffices())@php($routeAssignments = $document->officeAssignments->reject(fn ($assignment) => $waitingOffices->isNotEmpty() && $assignment->status === \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT))<span class="dts-count">{{ $routeAssignments->count() + $waitingOffices->count() }}</span></div>
            <div class="dts-workspace-route-list">
                {{-- While the client's offices wait for the paper copy, the Records Unit's holding row is left out. --}}
                @forelse ($routeAssignments as $assignment)
                    @php($canViewRouteHistory = $canViewAllHistory || $historyOfficeNames->contains($assignment->office))
                    <button type="button" class="dts-workspace-entry dts-workspace-route" data-workspace-route="{{ $assignment->office }}" data-workspace-route-history-access="{{ $canViewRouteHistory ? 'true' : 'false' }}" aria-pressed="false">
                        <span class="dts-workspace-entry-heading"><strong>{{ $assignment->office }}</strong><span class="dts-record-status" data-status="{{ str($assignment->status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($assignment->status) }}>{{ \App\Support\StatusLabel::for($assignment->status) }}</span></span>
                        {{-- Kept short: when it was sent and received (and by whom). The full story is in the history timeline.
                             The year shows only for another year. --}}
                        @if ($canViewRouteHistory)
                            @php($routeWhen = fn ($at) => $at?->timezone('Asia/Manila')->format($at?->timezone('Asia/Manila')->isCurrentYear() ? 'M j, g:i A' : 'M j, Y, g:i A'))
                            <span class="dts-route-steps">
                                <span class="dts-route-label">Sent</span><span class="dts-route-when">{{ $routeWhen($sentDates->get($assignment->office) ?? $assignment->created_at) }}</span>
                                @if ($receivedDates->has($assignment->office))
                                    <span class="dts-route-label">Received</span><span class="dts-route-when">{{ $routeWhen($receivedDates->get($assignment->office)) }}@if (filled($receivedBy[$assignment->office] ?? null)) by <span class="dts-route-who">{{ $receivedBy[$assignment->office] }}</span>@endif</span>
                                @endif
                            </span>
                        @endif
                    </button>
                @empty
                    @if ($waitingOffices->isEmpty())
                    <button type="button" class="dts-workspace-entry dts-workspace-route" data-workspace-route="{{ $document->current_office }}" data-workspace-route-history-access="{{ $canViewAllHistory || $historyOfficeNames->contains($document->current_office) ? 'true' : 'false' }}" aria-pressed="false"><strong>{{ $document->current_office }}</strong><span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($document->status) }}>{{ \App\Support\StatusLabel::for($document->status) }}</span></button>
                    @endif
                @endforelse
                {{-- Offices the client chose; they receive the document once Records confirms the paper copy. --}}
                @foreach ($waitingOffices as $waitingOffice)
                    <button type="button" class="dts-workspace-entry dts-workspace-route" data-workspace-route="{{ $waitingOffice }}" data-workspace-route-waiting data-workspace-route-history-access="{{ $canViewAllHistory || $historyOfficeNames->contains($waitingOffice) ? 'true' : 'false' }}" aria-pressed="false">
                        <span class="dts-workspace-entry-heading"><strong>{{ $waitingOffice }}</strong><span class="dts-record-status" data-status="waiting-for-paper-copy">{{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}</span></span>
                        <span class="dts-muted">Receives it after the document/s reach the Records Unit</span>
                    </button>
                @endforeach
            </div>
            {{-- Correct route: swap an office the user's office sent this to by mistake, before it receives it (dts.js). --}}
            @php($correctableRoutes = $document->correctableRoutesFor(request()->user()))
            @php($routeCorrection = ['url' => route('platform.documents.routes.correct', $document), 'tracking' => $document->tracking_number, 'subject' => $document->subject, 'routes' => $correctableRoutes, 'assigned' => $document->officeAssignments->whereNotIn('status', \App\Models\DocumentMovement::FINISHED_STATUSES)->pluck('office')->values(), 'list' => 'workspace-route-correction-offices'])
            @if ($correctableRoutes->isNotEmpty())
                <div class="dts-workspace-route-correction">
                    <button type="button" class="dts-button dts-button-secondary" data-route-correction='@json($routeCorrection)'><x-orchid-icon path="bs.arrow-left-right" /> Correct route</button>
                    <span class="dts-muted">Sent to the wrong office? Change it before that office receives it.</span>
                    <datalist id="workspace-route-correction-offices">@foreach (\App\Models\Document::officeOptions() as $officeOption)<option value="{{ $officeOption }}"></option>@endforeach</datalist>
                </div>
            @endif
            @if ($canRoute)
                <div class="dts-workspace-routing" data-workspace-routing data-save-url="{{ route('platform.documents.movements.store', $document) }}">
                    <p class="tw:rounded-lg tw:border tw:border-[var(--dts-border-strong)] tw:bg-[var(--dts-cream)] tw:p-4 tw:text-sm tw:text-[var(--dts-charcoal)]" data-workspace-route-readonly role="status" hidden>You don't have permission to edit this office's routing information.</p>
                    <div data-workspace-routing-controls>
                        <h4>Routing action</h4>
                        <div class="dts-workspace-form-errors" data-workspace-form-errors role="alert" hidden></div>
                        <dts-workflow-routing data-destination-actions='@json(\App\Models\DocumentMovement::DESTINATION_ACTIONS)' data-action-options='@json($workflowActionOptions)' data-active-routes='@json($document->officeAssignments->reject(fn ($assignment) => in_array($assignment->status, \App\Models\DocumentMovement::FINISHED_STATUSES, true))->pluck('office')->values())'>
                            <div class="dts-workspace-fields">
                                <label><span class="dts-workspace-source-heading">Acting office <span class="dts-record-status" data-workflow-source-status aria-live="polite"></span></span>
                                    <select name="workflow[source_office]" data-workflow-source-office aria-label="Acting office" required>
                                        @foreach ($workflowStates as $state)<option value="{{ $state['office'] }}" data-status="{{ str($state['status'])->lower()->replace(' ', '-') }}" data-status-label="{{ \App\Support\StatusLabel::for($state['status']) }}">{{ $state['office'] }}</option>@endforeach
                                    </select>
                                </label>
                                <label><span class="dts-workspace-label-row">Action</span>
                                    <select name="workflow[action]" data-workflow-action required><option value="">Choose an action</option></select>
                                </label>
                            </div>
                            <div data-destination-panel hidden>
                                <div class="dts-workspace-section-heading"><strong>Destination offices</strong><button type="button" class="dts-button dts-button-secondary" data-add-destination>Add destination</button></div>
                                <div data-destination-list>
                                    <div class="dts-workspace-destination" data-destination-row>
                                        <span data-destination-number>1</span>
                                        <label>Office<dts-searchable><input name="workflow[destinations][]" data-destination-select data-searchable data-options="workspace-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" placeholder="Search offices"><div data-suggestions role="listbox" hidden></div></dts-searchable></label>
                                        <div><label data-destination-remarks-label>Remarks</label><textarea name="workflow[destination_remarks][0]" data-destination-remarks rows="2" maxlength="1000"></textarea></div>
                                        <button type="button" class="dts-button dts-button-secondary" data-remove-destination>Remove</button>
                                    </div>
                                </div>
                                <p class="dts-field-hint">Anyone with the tracking number can read remarks. Leave out phone numbers, addresses, ID numbers, and health or money details.</p>
                                <datalist id="workspace-destination-offices">@foreach ($destinationOffices as $office)<option value="{{ $office }}"></option>@endforeach</datalist>
                                <template data-destination-template>
                                    <div class="dts-workspace-destination" data-destination-row>
                                        <span data-destination-number></span>
                                        <label>Office<dts-searchable><input name="workflow[destinations][]" data-destination-select data-searchable data-options="workspace-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" placeholder="Search offices"><div data-suggestions role="listbox" hidden></div></dts-searchable></label>
                                        <div><label data-destination-remarks-label>Remarks</label><textarea data-destination-remarks rows="2" maxlength="1000"></textarea></div>
                                        <button type="button" class="dts-button dts-button-secondary" data-remove-destination>Remove</button>
                                    </div>
                                </template>
                            </div>
                            <label class="dts-workspace-shared-remarks" data-shared-remarks><span>Remarks <span class="dts-optional">Optional</span></span><textarea name="workflow[remarks]" rows="3" maxlength="1000"></textarea><small class="dts-field-hint">Anyone with the tracking number can read remarks. Leave out phone numbers, addresses, ID numbers, and health or money details.</small></label>
                            <label class="dts-workspace-shared-remarks" data-release-recipient hidden><span>Name of person receiving the document <span class="dts-optional">Optional</span></span><input name="workflow[released_to]" maxlength="255" autocomplete="name" disabled><small class="dts-field-hint">Their full name, if they give it.</small></label>
                            @include('orchid.partials.release-signature', ['id' => 'workspace-release-signature'])
                        </dts-workflow-routing>
                        <div class="dts-workspace-actions"><button type="button" class="dts-button dts-button-primary" data-workspace-routing-save><span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-workspace-button-spinner aria-hidden="true" hidden></span><span data-workflow-submit-label>Save routing update</span></button></div>
                        @include('orchid.partials.office-remark', ['id' => 'workspace-office-remark'])
                    </div>
                </div>
            @endif
        </section>
        <section class="dts-workspace-panel" aria-labelledby="dts-workspace-history-heading">
            <div class="dts-workspace-section-heading"><div><p class="dts-eyebrow">AUDIT TRAIL</p><h3 id="dts-workspace-history-heading">Activity history</h3></div></div>
            <div class="dts-workspace-history-modes" aria-label="History scope"><button type="button" data-workspace-history-mode="all" aria-pressed="true">{{ $canViewAllHistory ? 'All document history' : 'My office history' }}</button><button type="button" data-workspace-history-mode="selected" aria-pressed="false" disabled>Selected route history</button></div>
            <p class="dts-muted" data-workspace-history-label hidden></p>
            <div class="dts-workspace-history-loading" data-workspace-history-loading role="status" hidden><span class="dts-workspace-spinner tw:motion-safe:animate-spin" aria-hidden="true"></span> Updating movement timeline…</div>
            <p class="dts-workspace-form-errors" data-workspace-history-error role="alert" hidden>Could not load activity. Try again.</p>
            <div class="dts-workspace-timeline" data-workspace-history-list>
                @include('orchid.partials.document-workspace-history')
            </div>
        </section>
    </div>
</div>
