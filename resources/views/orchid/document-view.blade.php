<div class="dts-workspace dts-document-view">
    <a class="dts-back" href="{{ route('platform.documents') }}"><x-orchid-icon path="bs.arrow-left" /> Document registry</a>

    <div class="tw:grid tw:grid-cols-1 tw:items-start tw:gap-4 tw:lg:grid-cols-[minmax(0,1fr)_280px]">
        <div class="tw:min-w-0 tw:grid tw:content-start tw:gap-4">
            <div class="dts-masthead tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-4">
                <div class="tw:min-w-0">
                    <p class="dts-eyebrow dts-tracking-eyebrow">{{ $document->tracking_number }} @include('orchid.partials.copy-button', ['text' => $document->tracking_number])</p>
                    <div class="dts-document-title-row">
                        <h2>{{ $document->subject }}</h2>
                        <span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($document->status) }}>{{ \App\Support\StatusLabel::for($document->status) }}</span>
                    </div>
                    <p class="dts-muted">{{ $document->document_type }} from {{ $document->origin }}</p>
                </div>
                @if ($canEditDocument)
                    <a class="dts-button dts-button-primary" href="{{ route('platform.documents.edit', $document) }}" data-workspace-url="{{ route('platform.documents.workspace', $document) }}" data-workspace-edit-open><x-orchid-icon path="bs.pencil" /> Edit document</a>
                @endif
            </div>

            <details class="dts-form-section tw:order-3" aria-labelledby="document-details-heading">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none"><h3 id="document-details-heading"><x-orchid-icon path="bs.file-earmark-text" /> Document details</h3></summary>
                <dl class="dts-detail-grid">
                    <div><dt>Document type</dt><dd>{{ $document->document_type }}</dd></div>
                    <div><dt>Originating office / school{{ $document->client_submission_key ? ' (self-reported)' : '' }}</dt><dd>{{ $document->origin }}</dd></div>
                    <div><dt>Sender{{ $document->client_submission_key ? ' (self-reported)' : '' }}</dt><dd>{{ $document->sender }}</dd></div>
                    @if (filled($document->delivery_method))
                        <div><dt>Mode of submission</dt><dd>{{ $document->delivery_method }}</dd></div>
                    @endif
                    <div><dt>Date received</dt><dd>{{ $document->received_at?->format('M d, Y') ?? 'Not received' }}</dd></div>
                    <div><dt>Time registered</dt><dd>{{ $document->created_at?->timezone('Asia/Manila')->format('M d, Y · g:i A') }}</dd></div>
                    <div class="tw:col-span-full dts-document-office-statuses">
                        <dt>Assigned offices</dt>
                        <dd>@include('orchid.cells.document-office-statuses', ['document' => $document, 'onDocumentPage' => true])</dd>
                    </div>
                    @if ($canViewAllHistory && filled($document->remarks))
                        <div class="tw:col-span-full"><dt>Latest routing remarks</dt><dd>{{ $document->remarks }}</dd></div>
                    @endif
                </dl>
                @if ($pendingRoutes->isNotEmpty())
                    <div class="dts-workflow-copy">
                        <strong>Requested destinations after the document/s are received</strong>
                        <ul>
                            @foreach ($pendingRoutes as $route)
                                <li>{{ $route->office }}{{ $route->remarks ? ': '.$route->remarks : '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </details>

            @if (filled($document->external_reference) || filled($document->description))
                <details class="dts-form-section tw:order-4">
                    <summary class="dts-section-heading tw:cursor-pointer tw:list-none">
                        <h3><x-orchid-icon path="bs.info-circle" /> More details</h3>
                        <span class="dts-count">Optional information</span>
                    </summary>
                    <dl class="dts-detail-grid">
                        @if (filled($document->external_reference))
                            <div><dt>External reference</dt><dd>{{ $document->external_reference }}</dd></div>
                        @endif
                        @if (filled($document->description))
                            <div><dt>Description</dt><dd>{{ $document->description }}</dd></div>
                        @endif
                    </dl>
                </details>
            @endif

            <section class="dts-form-section dts-routing-form tw:order-2" aria-labelledby="document-routing-heading">
                <div class="dts-section-heading"><h3 id="document-routing-heading"><x-orchid-icon path="bs.signpost-split" /> Next action</h3></div>

                @if ($errors->any())
                    <div class="dts-validation" role="alert">
                        <strong>Review the workflow information.</strong>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                @php
                    $availableActions = $workflowActionOptionsByOffice[$workflowOffice] ?? [];
                    // Includes the acting office: Restore may send the document back to it (dts.js blocks it for other actions).
                    $destinationOffices = \App\Models\Document::officeOptions();
                    $selectedAction = old('workflow.action', count($availableActions) === 1 && array_key_first($availableActions) !== \App\Models\DocumentMovement::ACTION_RESTORED ? array_key_first($availableActions) : '');
                    $selectedDestinations = old('workflow.destinations', array_filter([(string) old('workflow.destination')]));
                    $selectedDestinationRemarks = old('workflow.destination_remarks', []);
                @endphp

                @if (! $canManageWorkflow)
                    <div class="dts-workflow-notice">
                        <span class="dts-icon"><x-orchid-icon path="bs.lock" /></span>
                        <div>
                            <strong>Action unavailable</strong>
                            <p>@if (request()->user()?->isReadOnlySupervisor()) You have read-only access to this document. @endif This document is assigned to {{ $document->assignedOfficeNames()->join(', ', ' and ') }}. Only authorized staff assigned to those offices can take workflow actions.</p>
                        </div>
                    </div>
                @elseif ($availableActions === [])
                    <div class="dts-workflow-copy">
                        <strong>No routing update available</strong>
                        <p>This document cannot accept another routing update for {{ $workflowOffice }} while its status is {{ $workflowStatus }}.</p>
                    </div>
                @else
                    <dts-workflow-routing class="dts-form-grid" data-destination-actions='@json(\App\Models\DocumentMovement::DESTINATION_ACTIONS)' data-action-options='@json($workflowActionOptionsByOffice)' data-active-routes='@json($document->officeAssignments->reject(fn ($assignment) => in_array($assignment->status, \App\Models\DocumentMovement::FINISHED_STATUSES, true))->pluck('office')->values())'>
                        @if ($workflowStates->count() > 1)
                            <div class="dts-field dts-field-wide">
                                <label for="workflow-source-office">Acting office <span aria-hidden="true">*</span></label>
                                <select id="workflow-source-office" name="workflow[source_office]" required data-workflow-source-office>
                                    @foreach ($workflowStates as $state)
                                        <option value="{{ $state['office'] }}" @selected($workflowOffice === $state['office'])>{{ $state['office'] }} — {{ $state['status'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <input type="hidden" name="workflow[source_office]" value="{{ $workflowOffice }}" data-workflow-source-office>
                        @endif
                        <div class="dts-field">
                            <label for="workflow-action">Action <span aria-hidden="true">*</span></label>
                            <select id="workflow-action" name="workflow[action]" required data-workflow-action>
                                <option value="">Choose an action</option>
                                @foreach ($availableActions as $action => $label)
                                    <option value="{{ $action }}" @selected($selectedAction === $action)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dts-field dts-field-wide dts-route-destinations" data-destination-panel>
                            <div class="dts-route-destination-heading">
                                <label>Destination offices</label>
                                <button type="button" class="dts-button dts-button-secondary" data-add-destination><x-orchid-icon path="bs.plus-lg" /> Add destination</button>
                            </div>
                            <div class="dts-route-destination-list" data-destination-list>
                                @forelse ($selectedDestinations as $selectedDestination)
                                    <div class="dts-route-destination-row" data-destination-row>
                                        <span class="dts-route-destination-number" data-destination-number>{{ $loop->iteration }}</span>
                                        <div class="dts-route-destination-fields">
                                            <dts-searchable><input name="workflow[destinations][]" data-destination-select data-searchable data-options="workflow-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $selectedDestination }}" placeholder="Search offices" autocomplete="off" aria-label="Destination office {{ $loop->iteration }}"><div data-suggestions role="listbox" hidden></div></dts-searchable>
                                            <label for="workflow-destination-remarks-{{ $loop->index }}">Remarks for destination {{ $loop->iteration }} <span class="dts-optional">Optional</span></label>
                                            <textarea id="workflow-destination-remarks-{{ $loop->index }}" name="workflow[destination_remarks][{{ $loop->index }}]" data-destination-remarks rows="2" maxlength="1000" placeholder="Instructions for this office">{{ $selectedDestinationRemarks[$loop->index] ?? '' }}</textarea>
                                        </div>
                                        <button type="button" class="dts-icon-button" data-remove-destination aria-label="Remove destination {{ $loop->iteration }}"><x-orchid-icon path="bs.x-lg" /></button>
                                    </div>
                                @empty
                                    <div class="dts-route-destination-row" data-destination-row>
                                        <span class="dts-route-destination-number" data-destination-number>1</span>
                                        <div class="dts-route-destination-fields">
                                            <dts-searchable><input name="workflow[destinations][]" data-destination-select data-searchable data-options="workflow-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off" aria-label="Destination office 1"><div data-suggestions role="listbox" hidden></div></dts-searchable>
                                            <label for="workflow-destination-remarks-0">Remarks for destination 1 <span class="dts-optional">Optional</span></label>
                                            <textarea id="workflow-destination-remarks-0" name="workflow[destination_remarks][0]" data-destination-remarks rows="2" maxlength="1000" placeholder="Instructions for this office">{{ $selectedDestinationRemarks[0] ?? '' }}</textarea>
                                        </div>
                                        <button type="button" class="dts-icon-button" data-remove-destination aria-label="Remove destination 1"><x-orchid-icon path="bs.x-lg" /></button>
                                    </div>
                                @endforelse
                            </div>
                            <datalist id="workflow-destination-offices">
                                @foreach ($destinationOffices as $office)
                                    <option value="{{ $office }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="dts-field dts-field-wide" data-shared-remarks>
                            <label for="workflow-remarks">Remarks <span class="dts-optional">Optional</span></label>
                            <textarea id="workflow-remarks" name="workflow[remarks]" rows="3" maxlength="1000" placeholder="Remarks for this specific routing update">{{ old('workflow.remarks') }}</textarea>
                        </div>
                        <div class="dts-field dts-field-wide" data-release-recipient hidden>
                            <label for="workflow-released-to">Name of person receiving the document <span aria-hidden="true">*</span></label>
                            <input id="workflow-released-to" name="workflow[released_to]" value="{{ old('workflow.released_to') }}" maxlength="255" autocomplete="name" required disabled>
                        </div>
                        <template data-destination-template>
                            <div class="dts-route-destination-row" data-destination-row>
                                <span class="dts-route-destination-number" data-destination-number></span>
                                <div class="dts-route-destination-fields">
                                    <dts-searchable><input name="workflow[destinations][]" data-destination-select data-searchable data-options="workflow-destination-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off"><div data-suggestions role="listbox" hidden></div></dts-searchable>
                                    <label data-destination-remarks-label></label>
                                    <textarea data-destination-remarks rows="2" maxlength="1000" placeholder="Instructions for this office"></textarea>
                                </div>
                                <button type="button" class="dts-icon-button" data-remove-destination><x-orchid-icon path="bs.x-lg" /></button>
                            </div>
                        </template>
                    </dts-workflow-routing>
                    <div class="dts-workflow-actions">
                        <button type="submit" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.movements.store', $document) }}" formmethod="POST" data-confirm-message="Save this routing update?" data-confirm-title="Save routing update" data-confirm-label="Save update"><x-orchid-icon path="bs.signpost-split" /> <span data-workflow-submit-label>Save routing update</span></button>
                    </div>
                    @include('orchid.partials.office-remark', ['id' => 'document-office-remark'])
                @endif
            </section>

            <details class="dts-form-section tw:order-5" aria-labelledby="document-history-heading">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none"><h3 id="document-history-heading"><x-orchid-icon path="bs.clock-history" /> Movement timeline</h3></summary>
                @php
                    $timelineEntries = $document->movements->reverse()->values()
                        ->map(fn (\App\Models\DocumentMovement $movement): array => ['movement' => $movement, 'at' => $movement->created_at])
                        ->push(['movement' => null, 'at' => $document->created_at]);
                    $timelineDays = $timelineEntries->map(fn (array $entry, int $index): array => [...$entry, 'index' => $index])
                        ->groupBy(fn (array $entry): string => $entry['at']->toDateString());
                    $timelineIcons = ['moved' => 'bs.arrow-right', 'received' => 'bs.inbox', 'done' => 'bs.check-lg', 'hold' => 'bs.pause-fill', 'returned' => 'bs.arrow-return-left', 'cancelled' => 'bs.slash-circle', 'note' => 'bs.dot'];
                @endphp
                <div class="dts-staff-timeline">
                    @foreach ($timelineDays as $day => $entries)
                        @php
                            $timelineDate = \Illuminate\Support\Carbon::parse($day);
                        @endphp
                        <h4 class="dts-timeline-day">{{ $timelineDate->isToday() ? 'Today' : ($timelineDate->isYesterday() ? 'Yesterday' : $timelineDate->format('F j, Y')) }}</h4>
                        <ol class="dts-step-timeline">
                            @foreach ($entries as $entry)
                                @if ($entry['movement'] === null)
                                    <li data-kind="registered" style="--i: {{ $entry['index'] }}" @if ($entry['index'] === 0) data-latest @endif>
                                        <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon path="bs.file-earmark-check" /></span>
                                        <div class="tw:min-w-0 tw:pt-1">
                                            <strong>{{ $document->client_submission_key ? 'Document submitted' : 'Document registered' }}@if ($entry['index'] === 0) <span class="dts-timeline-latest">Latest</span>@endif</strong>
                                            <small class="dts-timeline-meta"><span>{{ $document->client_submission_key ? 'Client' : ($document->registeredBy?->name ?? 'System') }}</span><span aria-hidden="true">&middot;</span><time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('g:i A') }}</time></small>
                                            <small>{{ $document->origin }}</small>
                                        </div>
                                    </li>
                                @else
                                    @php
                                        $movement = $entry['movement'];
                                    @endphp
                                    <li data-kind="{{ $movement->publicKind() }}" style="--i: {{ $entry['index'] }}" @if ($entry['index'] === 0) data-latest @endif>
                                        <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon :path="$timelineIcons[$movement->publicKind()]" /></span>
                                        <div class="tw:min-w-0 tw:pt-1">
                                            <strong>{{ $movement->title() }}@if ($entry['index'] === 0) <span class="dts-timeline-latest">Latest</span>@endif</strong>
                                            <small class="dts-timeline-meta"><span>{{ $movement->actor?->name ?? 'System' }}</span><span aria-hidden="true">&middot;</span><time datetime="{{ $movement->created_at->toIso8601String() }}">{{ $movement->created_at->format('g:i A') }}</time></small>
                                            @if (filled($movement->from_office) && $movement->from_office !== $movement->to_office)
                                                <small>{{ $movement->from_office }} &rarr; {{ $movement->to_office }}</small>
                                            @else
                                                <small>{{ $movement->to_office }}</small>
                                            @endif
                                            @if ($movement->from_status === null)
                                                <small class="dts-timeline-status">{{ \App\Support\StatusLabel::for($movement->to_status) }}</small>
                                            @elseif ($movement->from_status !== $movement->to_status)
                                                <small class="dts-timeline-status">{{ \App\Support\StatusLabel::for($movement->from_status) }} &rarr; {{ \App\Support\StatusLabel::for($movement->to_status) }}</small>
                                            @endif
                                            @if ($document->client_submission_key && $movement->action === \App\Models\DocumentMovement::ACTION_SENT && $movement->from_office === null && filled($document->delivery_method))
                                                <small>Mode of submission: {{ $document->delivery_method }}</small>
                                            @endif
                                            @if ($movement->remarks)
                                                <p>{{ $movement->remarks }}</p>
                                            @endif
                                            @if ($movement->released_to)
                                                <small>Released to: {{ $movement->released_to }}</small>
                                            @endif
                                        </div>
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    @endforeach
                </div>
            </details>

            <details class="dts-form-section tw:order-6">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none">
                    <h3 id="document-audit-heading"><x-orchid-icon path="bs.shield-check" /> Audit trail</h3>
                    @if ($document->activities->isNotEmpty())
                        <span class="dts-count">Last {{ $document->activities->count() }} actions</span>
                    @endif
                </summary>
                <ol class="dts-audit-list">
                    @forelse ($document->activities as $activity)
                        <li>
                            <span class="dts-audit-icon" aria-hidden="true"><x-orchid-icon path="bs.activity" /></span>
                            <div>
                                <strong>{{ $activity->description }}</strong>
                                <small class="dts-timeline-meta"><span>{{ $activity->causer?->name ?? 'System' }}</span><span aria-hidden="true">&middot;</span><time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('M d, Y · g:i A') }}</time></small>
                                @if (data_get($activity->properties, 'file_name'))
                                    <small>{{ data_get($activity->properties, 'file_name') }}</small>
                                @elseif (filled(data_get($activity->properties, 'to_office')) && data_get($activity->properties, 'from_office') !== data_get($activity->properties, 'to_office'))
                                    <small>{{ data_get($activity->properties, 'from_office') }} &rarr; {{ data_get($activity->properties, 'to_office') }}</small>
                                @endif
                                @if ($activity->description === 'Document details updated')
                                    @include('orchid.partials.document-change-details', ['activity' => $activity])
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="dts-muted">No activity has been recorded.</li>
                    @endforelse
                </ol>
            </details>

        </div>

        <aside class="dts-document-rail tw:min-w-0">
            <details class="dts-form-section dts-tracking-label-section" aria-labelledby="tracking-label-heading">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none"><h3 id="tracking-label-heading"><x-orchid-icon path="bs.upc-scan" /> Tracking label</h3></summary>
                @include('orchid.partials.tracking-label')
                <a class="dts-button dts-button-secondary dts-icon-only dts-print-label-button" href="{{ route('platform.documents.label', $document) }}" target="_blank" rel="noopener noreferrer" data-tooltip="Print label"><x-orchid-icon path="bs.printer" /><span class="visually-hidden">Print label</span></a>
            </details>
            <details class="dts-form-section dts-document-summary" aria-labelledby="document-status-heading">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none"><h3 id="document-status-heading">Record summary</h3></summary>
                <dl class="dts-metadata">
                    <dt>Tracking number</dt><dd>{{ $document->tracking_number }} @include('orchid.partials.copy-button', ['text' => $document->tracking_number])</dd>
                    <dt>Registered by</dt><dd>{{ $document->client_submission_key ? 'Client submission' : ($document->registeredBy?->name ?? 'System') }}</dd>
                    <dt>Registered on</dt><dd>{{ $document->created_at->format('M d, Y · g:i A') }}</dd>
                    <dt>Last changed by</dt><dd>{{ $lastChangedBy }}</dd>
                    <dt>Last changed on</dt><dd>{{ $lastChangedAt->format('M d, Y · g:i A') }}</dd>
                    {{-- Staff limited to their own office's activity only see their office's downloads; the screen sets
                         downloadsScopedToOffice when it narrows that data, and the wording says so. --}}
                    @php($officeDownloads = $downloadsScopedToOffice ?? false)
                    @php($notDownloaded = $officeDownloads ? 'Not downloaded by your office yet' : 'Not downloaded yet')
                    <dt>{{ $officeDownloads ? 'Last downloaded by your office' : 'Last downloaded by' }}</dt><dd>{{ $lastDownloadedBy ?? $notDownloaded }}</dd>
                    <dt>Last downloaded on</dt><dd>{{ $lastDownloadedAt?->format('M d, Y · g:i A') ?? $notDownloaded }}</dd>
                    @if ($lastDownloadedFile)
                        <dt>Downloaded file</dt><dd>{{ $lastDownloadedFile }}</dd>
                    @endif
                </dl>
            </details>

        </aside>
    </div>
</div>
@include('orchid.partials.document-workspace-dialog')
<button type="button" hidden data-workspace-auto-open data-workspace-url="{{ route('platform.documents.workspace', $document) }}" data-workspace-office="{{ request()->query('source_office') }}" data-workspace-return-url="{{ route('platform.documents') }}"></button>
