<div class="dts-workspace">
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <h2>{{ match ($section) { 'incoming' => 'Incoming documents', 'outgoing' => 'Outgoing documents', 'archived' => 'Archived documents', 'documents' => 'Document registry', default => 'Document overview' } }}</h2>
        </div>
        @unless (request()->user()?->isReadOnlySupervisor())
            <a class="dts-button dts-button-primary" href="{{ route('platform.documents.create') }}"><x-orchid-icon path="bs.plus-lg" /> New document</a>
        @endunless
    </div>
    {{-- One full-width column: the queue gets the room. Notifications live in the top-bar bell. --}}
    <div class="tw:grid tw:grid-cols-1 tw:gap-6">
        @php $isSupervisor = (bool) request()->user()?->isReadOnlySupervisor(); @endphp
        <section class="dts-registry tw:min-w-0" aria-labelledby="{{ $isSupervisor ? 'registry-title' : 'office-queue-title' }}">
            @unless ($isSupervisor)
            {{-- Work summary: bold cards that each open the list behind their number. "Needs action" counts the queue
                 below; the cards that open a menu page carry that page's name (Incoming, Outgoing, Archived). --}}
            @php
                $summaryCards = [
                    ['Needs action', $officeQueueDocumentCount, Str::plural('document', $officeQueueDocumentCount).' waiting for your office', 'bs.inbox', 'green', '#office-queue-title'],
                    ['Incoming', $notReceivedCount, 'not received yet; receive these first', 'bs.box-arrow-in-down', 'gold', route('platform.incoming')],
                    ['Outgoing', $sentWaitingCount, 'sent; the next office has not received '.($sentWaitingCount === 1 ? 'it' : 'them').' yet', 'bs.send', 'blue', route('platform.outgoing')],
                    ['Archived', $archivedCount, Str::plural('document', $archivedCount).' completed or released', 'bs.check2-circle', 'teal', route('platform.archived')],
                ];
            @endphp
            <ul class="dts-metrics dts-metrics-bold" aria-label="Work summary" data-metric-count="{{ count($summaryCards) }}">
                @foreach ($summaryCards as [$label, $value, $caption, $icon, $tone, $href])
                    <li>
                        <a class="dts-metric dts-metric-{{ $tone }}" data-summary-card href="{{ $href }}">
                            <span class="dts-metric-heading"><span>{{ $label }}</span><span class="dts-icon" aria-hidden="true"><x-orchid-icon :path="$icon" /></span></span>
                            <strong>{{ $value }}</strong>
                            <small>{{ $caption }}</small>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="dts-office-queue" aria-labelledby="office-queue-title">
                <div class="dts-section-heading tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
                    <div>
                        {{-- The office name is the heading, so staff see at once whose queue this is. --}}
                        <h3 id="office-queue-title">{{ $workspaceOffice === 'All offices' ? 'Action queue' : $workspaceOffice }}</h3>
                        <p class="dts-section-caption">{{ $workspaceOffice === 'All offices' ? 'All offices' : 'Documents waiting for your office' }}</p>
                        @if (($paperWaitingCount ?? 0) > 0)
                            <p class="dts-section-description">Client document/s: receive them, print the label, then send them to the offices.</p>
                        @endif
                    </div>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                        <span class="dts-count dts-record-count"><strong>{{ $officeQueueCount }}</strong> {{ Str::plural('action', $officeQueueCount) }}</span>
                    </div>
                </div>
                <div class="dts-table-frame">
                    <div class="dts-table-scroll">
                      <table class="dts-table dts-office-queue-table">
                        <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Offices and status</th><th scope="col">Quick action</th></tr></thead>
                        <tbody>
                            @forelse ($officeQueue as $queueItem)
                                @php
                                    $queueActionIcons ??= [
                                        \App\Models\DocumentMovement::ACTION_RECEIVED => ['bs.box-arrow-in-down', 'Receive'],
                                        \App\Models\DocumentMovement::ACTION_FORWARDED => ['bs.send', 'Forward'],
                                        \App\Models\DocumentMovement::ACTION_COMPLETED => ['bs.check2-circle', 'Complete'],
                                        \App\Models\DocumentMovement::ACTION_RELEASED => ['bs.box-arrow-up-right', 'Release'],
                                    ];
                                    $document = $queueItem['document'];
                                    $queueOffices = $queueItem['offices'];
                                    $singleOffice = $queueOffices->count() === 1 ? $queueOffices->first()['office'] : null;
                                    $queueDocumentUrl = route('platform.documents.view', array_filter(['document' => $document, 'source_office' => $singleOffice]));
                                    $queueWorkspaceUrl = route('platform.documents.workspace', $document);
                                    $awaitsPaper = $queueOffices->contains('status', \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT);
                                    $waitingOffices = $awaitsPaper ? $document->pendingRouteOffices() : collect();
                                    // Icon buttons for every action the backend allows this user's queue offices (first office wins).
                                    $queueActions = collect();
                                    // Receive covers every one of this user's routes still waiting on this document, in one click.
                                    $receiveOffices = collect();
                                    if (! $awaitsPaper) {
                                        foreach ($queueOffices as $queueOffice) {
                                            $officeActions = app(\App\Services\DocumentRoutingService::class)->availableActionOptions(auth()->user(), $document, $queueOffice);
                                            if (array_key_exists(\App\Models\DocumentMovement::ACTION_RECEIVED, $officeActions)) {
                                                $receiveOffices->push($queueOffice['office']);
                                            }
                                            foreach (array_keys($officeActions) as $action) {
                                                if ($action !== \App\Models\DocumentMovement::ACTION_RESTORED && ! $queueActions->has($action)) {
                                                    $queueActions->put($action, $queueOffice['office']);
                                                }
                                            }
                                        }
                                        $actionOrder = array_keys($queueActionIcons);
                                        $queueActions = $queueActions->sortBy(fn (string $office, string $action): int => array_search($action, $actionOrder, true) === false ? 99 : array_search($action, $actionOrder, true));
                                    }
                                @endphp
                                <tr data-office-queue-row>
                                    <td><div class="dts-record-link"><div class="dts-record-code"><a href="{{ $queueDocumentUrl }}" data-workspace-url="{{ $queueWorkspaceUrl }}"><strong>{{ $document->tracking_number }}</strong></a>@include('orchid.partials.copy-button', ['text' => $document->tracking_number])</div><span>{{ $document->subject }}</span></div></td>
                                    <td>
                                        <div class="dts-office-status-list">
                                            @foreach ($queueOffices as $queueOffice)
                                                {{-- The Records Unit's holding row is replaced by the client's offices below. --}}
                                                @continue($waitingOffices->isNotEmpty() && $queueOffice['status'] === \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
                                                <a class="dts-office-status-item" data-office-queue-office href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $queueOffice['office']]) }}" data-workspace-url="{{ $queueWorkspaceUrl }}" data-workspace-office="{{ $queueOffice['office'] }}" aria-label="View {{ $queueOffice['office'] }} workflow, {{ \App\Support\StatusLabel::for($queueOffice['status']) }}">
                                                    <span class="dts-office-status-name">{{ $queueOffice['office'] }}</span>
                                                    <span class="dts-record-status" data-status="{{ str($queueOffice['status'])->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($queueOffice['status']) }}>{{ \App\Support\StatusLabel::for($queueOffice['status']) }}</span>
                                                </a>
                                            @endforeach
                                            @if ($awaitsPaper)
                                                @foreach ($waitingOffices as $waitingOffice)
                                                    <a class="dts-office-status-item" data-office-queue-office href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $waitingOffice]) }}" data-workspace-url="{{ $queueWorkspaceUrl }}" data-workspace-office="{{ $waitingOffice }}" aria-label="View {{ $waitingOffice }} workflow, {{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}">
                                                        <span class="dts-office-status-name">{{ $waitingOffice }}</span>
                                                        <span class="dts-record-status" data-status="waiting-for-paper-copy">{{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}</span>
                                                    </a>
                                                @endforeach
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        {{-- Icon-only buttons; each label shows as a tooltip on hover and on keyboard focus. --}}
                                        <div class="dts-queue-icons">
                                            @if (($canConfirmPaperIntake ?? false) && $awaitsPaper)
                                                <button type="button" class="dts-queue-icon is-primary dts-paper-received" data-paper-received="{{ $document->tracking_number }}" data-lookup-url="{{ route('platform.documents.intake') }}" data-tooltip="Document/s received" aria-label="Document/s received for {{ $document->tracking_number }}"><x-orchid-icon path="bs.check2-circle" /></button>
                                            @endif
                                            @foreach ($queueActions as $action => $actionOffice)
                                                @if ($action === \App\Models\DocumentMovement::ACTION_RECEIVED)
                                                    <button type="button" @class(['dts-queue-icon', 'is-primary' => $loop->first]) data-receive-routes="{{ $receiveOffices->toJson() }}" data-receive-url="{{ route('platform.documents.movements.store', $document) }}" data-tracking-number="{{ $document->tracking_number }}" data-tooltip="{{ $receiveOffices->count() > 1 ? 'Receive all '.$receiveOffices->count().' routes' : 'Receive' }}" aria-label="Receive {{ $document->tracking_number }} for {{ $receiveOffices->join(', ', ' and ') }}"><x-orchid-icon :path="$queueActionIcons[$action][0]" /></button>
                                                    @continue
                                                @endif
                                                <a @class(['dts-queue-icon', 'is-primary' => $loop->first]) href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $actionOffice]) }}" data-workspace-url="{{ $queueWorkspaceUrl }}" data-workspace-office="{{ $actionOffice }}" data-workspace-action="{{ $action }}" data-tooltip="{{ ($queueActionIcons[$action][1] ?? $action).($queueOffices->count() > 1 ? ' · '.$actionOffice : '') }}" aria-label="{{ $queueActionIcons[$action][1] ?? $action }} {{ $document->tracking_number }} for {{ $actionOffice }}"><x-orchid-icon :path="$queueActionIcons[$action][0] ?? 'bs.lightning'" /></a>
                                            @endforeach
                                            {{-- The label is printed only once the document/s are received; Document/s received adds it to the row then. --}}
                                            @unless ($awaitsPaper || $receiveOffices->isNotEmpty())
                                                <a class="dts-queue-icon" href="{{ route('platform.documents.label', $document) }}" target="_blank" rel="noopener" data-turbo="false" data-tooltip="Print label" aria-label="Print label for {{ $document->tracking_number }}"><x-orchid-icon path="bs.printer" /></a>
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3"><div class="dts-empty dts-empty-compact">
                                    <span class="dts-empty-icon"><x-orchid-icon path="bs.check2-circle" /></span>
                                    <h4>No pending office actions</h4>
                                    <p>{{ $workspaceOffice === 'No office assigned' ? 'Ask an administrator to assign your account to an office.' : 'There are no documents waiting for action in this workspace.' }}</p>
                                </div></td></tr>
                            @endforelse
                        </tbody>
                      </table>
                    </div>
                </div>
                {{-- Pages of the queue, with the same pager and "Rows per page" as the document registry (PlatformScreen
                     pages it by queue_page and queue_per_page). It stays at the bottom of the window while the queue is
                     on screen (dts.css .dts-queue-pager). --}}
                @if ($officeQueueDocumentCount > min($queuePageSizes))
                    @php
                        $queuePaginator = (new \Illuminate\Pagination\LengthAwarePaginator($officeQueue, $officeQueueDocumentCount, $queuePerPage, $queuePage, [
                            'path' => route('platform.main'),
                            'pageName' => 'queue_page',
                        ]))->appends(array_filter(['queue_per_page' => $queuePerPage === 25 ? null : $queuePerPage]))->fragment('office-queue-title')->onEachSide(1);
                        $queueFirst = ($queuePage - 1) * $queuePerPage + 1;
                        $queueLast = min($queuePage * $queuePerPage, $officeQueueDocumentCount);
                    @endphp
                    <nav class="dts-queue-pager" aria-label="Action queue pages">
                        <p class="dts-queue-pager-count" role="status">Showing {{ $queueFirst }}–{{ $queueLast }} of {{ $officeQueueDocumentCount }} documents</p>
                        <div class="dts-queue-pager-controls">
                            <div class="dts-page-size-toolbar">
                                <label for="queue-page-size">Rows per page</label>
                                <select id="queue-page-size" data-queue-page-size data-queue-url="{{ route('platform.main') }}">
                                    @foreach ($queuePageSizes as $queuePageSize)
                                        <option value="{{ $queuePageSize }}" @selected($queuePerPage === $queuePageSize)>{{ $queuePageSize }}</option>
                                    @endforeach
                                </select>
                            </div>
                            {{ $queuePaginator->links('platform::partials.pagination') }}
                        </div>
                    </nav>
                @endif
            </div>
            @endunless
            @if ($isSupervisor)
            <div class="dts-section-heading"><h3 id="registry-title">Recent documents</h3></div>
            <nav class="dts-tabs" aria-label="Document views">
                @foreach (['documents' => 'All documents', 'incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'archived' => 'Archived'] as $key => $label)
                    <a href="{{ route('platform.'.$key) }}" @class(['dts-tab', 'is-active' => $section === $key || ($section === 'dashboard' && $key === 'documents')]) @if ($section === $key || ($section === 'dashboard' && $key === 'documents')) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="dts-table-frame">
              <div class="dts-table-scroll">
                <table class="dts-table dts-recent-documents-table">
                    <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Assigned offices</th><th scope="col">Last updated</th></tr></thead>
                    <tbody>
                        @forelse ($recentDocuments as $document)
                            <tr>
                                <td><div class="dts-record-link"><div class="dts-record-code"><a href="{{ route('platform.documents.view', $document) }}" data-workspace-url="{{ route('platform.documents.workspace', $document) }}"><strong>{{ $document->tracking_number }}</strong></a>@include('orchid.partials.copy-button', ['text' => $document->tracking_number])</div><span>{{ $document->subject }}</span></div></td>
                                <td>@include('orchid.cells.document-office-statuses', ['document' => $document])</td>
                                <td><span>{{ $document->updated_at->format('M d, Y') }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="dts-empty">
                                <span class="dts-empty-icon"><x-orchid-icon path="bs.folder2-open" /></span>
                                <h4>No documents registered yet</h4>
                                <p>Your document registry is currently empty.</p>
                            </div></td></tr>
                        @endforelse
                    </tbody>
                </table>
              </div>
            </div>
            @endif
        </section>
        @if ($section === 'dashboard')
            {{-- Nice to know, not urgent: kept under the queue and closed until someone opens it. --}}
            <details class="dts-form-section dts-dashboard-recent" aria-labelledby="dashboard-recent-heading">
                <summary class="dts-section-heading tw:cursor-pointer tw:list-none"><h3 id="dashboard-recent-heading"><x-orchid-icon path="bs.clock-history" /> Recent activity</h3></summary>
                @forelse ($recentActivity as $movement)
                    @if ($loop->first)<ol class="dts-record-timeline dts-dashboard-activity">@endif
                        <li>
                            <span aria-hidden="true"></span>
                            <div>
                                <strong><a href="{{ route('platform.documents.view', $movement->document) }}" data-workspace-url="{{ route('platform.documents.workspace', $movement->document) }}">{{ $movement->title() }}</a></strong>
                                <small>{{ $movement->document->tracking_number }}</small>
                                <small>{{ $movement->actor?->name ?? 'System' }} &middot; {{ $movement->created_at->diffForHumans() }}</small>
                            </div>
                        </li>
                    @if ($loop->last)</ol>@endif
                @empty
                    <div class="dts-activity-empty"><x-orchid-icon path="bs.clock-history" /><p>No document activity yet.</p></div>
                @endforelse
            </details>
        @endif
    </div>
</div>
@include('orchid.partials.document-workspace-dialog')
