<div class="dts-workspace dts-document-view">
    <a class="dts-back" href="{{ route('platform.documents') }}"><x-orchid-icon path="bs.arrow-left" /> Document registry</a>

    <div class="tw:grid tw:grid-cols-1 tw:items-start tw:gap-4 tw:lg:grid-cols-[minmax(0,1fr)_280px]">
        <div class="tw:min-w-0 tw:grid tw:content-start tw:gap-4">
            <div class="dts-masthead tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-4">
                <div class="tw:min-w-0">
                    <p class="dts-eyebrow">{{ $document->tracking_number }}</p>
                    <div class="dts-document-title-row">
                        <h2>{{ $document->subject }}</h2>
                        <span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}">{{ $document->status }}</span>
                    </div>
                    <p class="dts-muted">{{ $document->document_type }} from {{ $document->origin }}</p>
                </div>
            </div>

            <section class="dts-form-section" aria-labelledby="document-details-heading">
                <div class="dts-section-heading"><h3 id="document-details-heading"><x-orchid-icon path="bs.file-earmark-text" /> Document details</h3></div>
                <dl class="dts-detail-grid">
                    <div><dt>Document type</dt><dd>{{ $document->document_type }}</dd></div>
                    <div><dt>External reference</dt><dd>{{ $document->external_reference ?: 'Not specified' }}</dd></div>
                    <div><dt>Originating office / school</dt><dd>{{ $document->origin }}</dd></div>
                    <div><dt>Sender</dt><dd>{{ $document->sender }}</dd></div>
                    <div><dt>Date received</dt><dd>{{ $document->received_at->format('M d, Y') }}</dd></div>
                    <div><dt>Due date</dt><dd>{{ $document->due_at?->format('M d, Y') ?? 'Not set' }}</dd></div>
                    <div><dt>Current office</dt><dd>{{ $document->current_office }}</dd></div>
                    <div><dt>Priority</dt><dd>{{ $document->priority }}</dd></div>
                </dl>
                <dl class="dts-metadata dts-document-notes">
                    <div><dt>Description</dt><dd>{{ $document->description ?: 'No description provided.' }}</dd></div>
                    <div><dt>Routing remarks</dt><dd>{{ $document->remarks ?: 'No routing remarks.' }}</dd></div>
                </dl>
            </section>

            <section class="dts-form-section dts-routing-form" aria-labelledby="document-routing-heading">
                <div class="dts-section-heading"><h3 id="document-routing-heading"><x-orchid-icon path="bs.signpost-split" /> Document workflow</h3></div>

                @if ($errors->any())
                    <div class="dts-validation" role="alert">
                        <strong>Review the workflow information.</strong>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                @if (! $canManageWorkflow)
                    <div class="dts-workflow-notice">
                        <span class="dts-icon"><x-orchid-icon path="bs.lock" /></span>
                        <div>
                            <strong>Action unavailable</strong>
                            <p>This document is assigned to {{ $document->current_office }}. Only staff assigned to that office can take workflow actions.</p>
                        </div>
                    </div>
                @elseif (in_array($document->status, ['Awaiting receipt', 'Forwarded'], true))
                    <div class="dts-workflow-copy">
                        <strong>Acknowledge receipt</strong>
                        <p>Confirm that {{ $document->current_office }} has received and accepted this document.</p>
                    </div>
                    <div class="dts-form-grid">
                        <div class="dts-field dts-field-wide">
                            <label for="workflow-acknowledge-remarks">Receipt note <span aria-hidden="true">*</span></label>
                            <textarea id="workflow-acknowledge-remarks" name="workflow[remarks]" rows="3" maxlength="1000" required placeholder="Receiving note or acknowledgement details">{{ old('workflow.remarks') }}</textarea>
                        </div>
                    </div>
                    <div class="dts-workflow-actions">
                        <button type="submit" name="workflow[action]" value="acknowledge" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.movements.store', $document) }}" formmethod="POST" data-confirm-message="Acknowledge receipt of this document?"><x-orchid-icon path="bs.check2-circle" /> Acknowledge receipt</button>
                    </div>
                @elseif ($document->status === 'Received')
                    @php($destinationOffices = array_values(array_diff(\App\Models\Document::OFFICES, [$document->current_office])))
                    <div class="dts-workflow-copy">
                        <strong>Route this document</strong>
                        <p>Forward it to the next office, or archive it when processing is complete.</p>
                    </div>
                    <div class="dts-form-grid">
                        <div class="dts-field">
                            <label for="workflow-destination">Destination office</label>
                            <select id="workflow-destination" name="workflow[destination]">
                                @foreach ($destinationOffices as $office)
                                    <option value="{{ $office }}" @selected(old('workflow.destination') === $office)>{{ $office }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dts-field dts-field-wide">
                            <label for="workflow-routing-remarks">Action note <span aria-hidden="true">*</span></label>
                            <textarea id="workflow-routing-remarks" name="workflow[remarks]" rows="3" maxlength="1000" required placeholder="Routing instructions or reason for archiving">{{ old('workflow.remarks') }}</textarea>
                        </div>
                    </div>
                    <div class="dts-workflow-actions">
                        <button type="submit" name="workflow[action]" value="archive" class="dts-button dts-button-danger" formaction="{{ route('platform.documents.movements.store', $document) }}" formmethod="POST" data-confirm-message="Archive this document? It will leave the active office queue."><x-orchid-icon path="bs.archive" /> Archive</button>
                        <button type="submit" name="workflow[action]" value="forward" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.movements.store', $document) }}" formmethod="POST" data-confirm-message="Forward this document to the selected office?"><x-orchid-icon path="bs.send" /> Forward document</button>
                    </div>
                @elseif ($document->status === 'Archived')
                    <div class="dts-workflow-copy">
                        <strong>Restore this document</strong>
                        <p>Return the document to an office for further processing.</p>
                    </div>
                    <div class="dts-form-grid">
                        <div class="dts-field">
                            <label for="workflow-restore-destination">Destination office <span aria-hidden="true">*</span></label>
                            <select id="workflow-restore-destination" name="workflow[destination]" required>
                                @foreach (\App\Models\Document::OFFICES as $office)
                                    <option value="{{ $office }}" @selected(old('workflow.destination', $document->current_office) === $office)>{{ $office }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dts-field dts-field-wide">
                            <label for="workflow-restore-remarks">Reason for restoring <span aria-hidden="true">*</span></label>
                            <textarea id="workflow-restore-remarks" name="workflow[remarks]" rows="3" maxlength="1000" required placeholder="Why does this document need further processing?">{{ old('workflow.remarks') }}</textarea>
                        </div>
                    </div>
                    <div class="dts-workflow-actions">
                        <button type="submit" name="workflow[action]" value="restore" class="dts-button dts-button-primary" formaction="{{ route('platform.documents.movements.store', $document) }}" formmethod="POST" data-confirm-message="Restore this document to the selected office?"><x-orchid-icon path="bs.arrow-counterclockwise" /> Restore document</button>
                    </div>
                @endif
            </section>

            <section class="dts-form-section" aria-labelledby="document-history-heading">
                <div class="dts-section-heading"><h3 id="document-history-heading"><x-orchid-icon path="bs.clock-history" /> Movement timeline</h3></div>
                <ol class="dts-record-timeline">
                    @foreach ($document->movements as $movement)
                        <li>
                            <span aria-hidden="true"></span>
                            <div>
                                <strong>{{ $movement->title() }}</strong>
                                <small>{{ $movement->actor?->name ?? 'System' }} &middot; {{ $movement->created_at->format('M d, Y g:i A') }}</small>
                                @if ($movement->from_office !== $movement->to_office)
                                    <small>{{ $movement->from_office }} &rarr; {{ $movement->to_office }}</small>
                                @endif
                                @if ($movement->from_status !== $movement->to_status)
                                    <small>{{ $movement->from_status }} &rarr; {{ $movement->to_status }}</small>
                                @endif
                                @if ($movement->remarks)
                                    <p>{{ $movement->remarks }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                    <li>
                        <span aria-hidden="true"></span>
                        <div>
                            <strong>Document registered</strong>
                            <small>{{ $document->registeredBy?->name ?? 'System' }} &middot; {{ $document->created_at->format('M d, Y g:i A') }}</small>
                            <small>Assigned to {{ $document->movements->last()?->from_office ?? $document->current_office }}</small>
                        </div>
                    </li>
                </ol>
            </section>

            <section class="dts-form-section" aria-labelledby="document-audit-heading">
                <div class="dts-section-heading">
                    <h3 id="document-audit-heading"><x-orchid-icon path="bs.shield-check" /> Audit trail</h3>
                    <span class="dts-count">Latest {{ $document->activities->count() }}</span>
                </div>
                <ol class="dts-audit-list">
                    @forelse ($document->activities as $activity)
                        <li>
                            <span class="dts-audit-icon" aria-hidden="true"><x-orchid-icon path="bs.activity" /></span>
                            <div>
                                <strong>{{ $activity->description }}</strong>
                                <small>{{ $activity->causer?->name ?? 'System' }} &middot; {{ $activity->created_at->format('M d, Y g:i A') }}</small>
                                @if (data_get($activity->properties, 'file_name'))
                                    <small>{{ data_get($activity->properties, 'file_name') }}</small>
                                @elseif (data_get($activity->properties, 'from_office') !== data_get($activity->properties, 'to_office'))
                                    <small>{{ data_get($activity->properties, 'from_office') }} &rarr; {{ data_get($activity->properties, 'to_office') }}</small>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="dts-muted">No activity has been recorded.</li>
                    @endforelse
                </ol>
            </section>

        </div>

        <aside class="dts-document-rail tw:min-w-0">
            <section class="dts-form-section dts-document-summary" aria-labelledby="document-status-heading">
                <h3 id="document-status-heading">Record summary</h3>
                <dl class="dts-metadata">
                    <dt>Tracking number</dt><dd>{{ $document->tracking_number }}</dd>
                    <dt>Registered by</dt><dd>{{ $document->registeredBy?->name ?? 'System' }}</dd>
                    <dt>Registered on</dt><dd>{{ $document->created_at->format('M d, Y g:i A') }}</dd>
                    <dt>Last updated</dt><dd>{{ $document->updated_at->format('M d, Y g:i A') }}</dd>
                </dl>
            </section>

            <section class="dts-form-section" aria-labelledby="document-attachments-heading">
                <div class="dts-section-heading tw:flex tw:items-center tw:justify-between tw:gap-3">
                    <h3 id="document-attachments-heading"><x-orchid-icon path="bs.paperclip" /> Attachments</h3>
                    <span class="dts-count">{{ $document->attachments->count() }} {{ Str::plural('file', $document->attachments->count()) }}</span>
                </div>

                @forelse ($document->attachments as $attachment)
                    <div class="dts-stored-attachment">
                        <span class="dts-icon"><x-orchid-icon path="bs.file-earmark" /></span>
                        <div class="dts-attachment-info">
                            <strong>{{ $attachment->original_name }}</strong>
                            <small>{{ strtoupper($attachment->extension) }} &middot; {{ Illuminate\Support\Number::fileSize($attachment->size) }}</small>
                        </div>
                        <div class="dts-attachment-actions">
                            <a class="dts-icon-button" href="{{ route('platform.documents.attachments.preview', [$document, $attachment]) }}" target="_blank" rel="noopener" aria-label="Preview {{ $attachment->original_name }}" title="Preview"><x-orchid-icon path="bs.eye" /></a>
                            <a class="dts-icon-button" href="{{ route('platform.documents.attachments.download', [$document, $attachment]) }}" aria-label="Download {{ $attachment->original_name }}" title="Download"><x-orchid-icon path="bs.download" /></a>
                        </div>
                    </div>
                @empty
                    <div class="dts-empty dts-empty-compact">
                        <span class="dts-empty-icon"><x-orchid-icon path="bs.paperclip" /></span>
                        <h4>No attachments</h4>
                        <p>No files were uploaded with this document.</p>
                    </div>
                @endforelse
            </section>

        </aside>
    </div>
</div>
