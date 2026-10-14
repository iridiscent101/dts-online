<div class="dts-workspace">
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <h2>{{ match ($section) { 'incoming' => 'Incoming documents', 'outgoing' => 'Outgoing documents', 'archived' => 'Document archive', 'documents' => 'Document registry', default => 'Document overview' } }}</h2>
        </div>
        @unless (request()->user()?->isReadOnlySupervisor())
            <a class="dts-button dts-button-primary" href="{{ route('platform.documents.create') }}"><x-orchid-icon path="bs.plus-lg" /> New document</a>
        @endunless
    </div>
    <div class="tw:grid tw:grid-cols-1 tw:gap-6 {{ $section === 'dashboard' ? 'tw:xl:grid-cols-[minmax(0,1fr)_260px]' : '' }}">
        @php $isSupervisor = (bool) request()->user()?->isReadOnlySupervisor(); @endphp
        <section class="dts-registry tw:min-w-0" aria-labelledby="{{ $isSupervisor ? 'registry-title' : 'office-queue-title' }}">
            @unless ($isSupervisor)
            {{-- Work summary: every number is about documents that still need this office (or, last card, what it finished). --}}
            @php
                $summaryCards = [
                    ['Needs action', $officeQueueDocumentCount, Str::plural('document', $officeQueueDocumentCount).' waiting', 'bs.inbox', 'green'],
                    ['Overdue', $officeOverdueCount, 'past the due date', 'bs.exclamation-triangle', $officeOverdueCount > 0 ? 'red' : 'neutral'],
                    ['Due within 2 days', $officeDueSoonCount, 'due today to '.today('Asia/Manila')->addDays(2)->format('M d'), 'bs.calendar-event', $officeDueSoonCount > 0 ? 'amber' : 'neutral'],
                    $paperWaitingCount !== null
                        ? ['Paper waiting', $paperWaitingCount, 'client copies to receive', 'bs.file-earmark-text', $paperWaitingCount > 0 ? 'amber' : 'neutral']
                        : ['Done this week', $actedThisWeekCount ?? 0, 'documents your office moved on', 'bs.check2-circle', 'green'],
                ];
            @endphp
            <div class="dts-metrics" role="list" aria-label="Work summary">
                @foreach ($summaryCards as [$label, $value, $caption, $icon, $tone])
                    <div class="dts-metric dts-{{ $tone }}" role="listitem" data-summary-card>
                        <div class="dts-metric-heading"><span>{{ $label }}</span><span class="dts-icon"><x-orchid-icon :path="$icon" /></span></div>
                        <strong>{{ $value }}</strong>
                        <small>{{ $caption }}</small>
                    </div>
                @endforeach
            </div>
            <div class="dts-office-queue" aria-labelledby="office-queue-title">
                <div class="dts-section-heading tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
                    <div>
                        <h3 id="office-queue-title">{{ $workspaceOffice === 'All offices' ? 'Action queue' : 'My office queue' }}</h3>
                        <p class="dts-section-caption">{{ $workspaceOffice }}</p>
                        @if (($paperWaitingCount ?? 0) > 0)
                            <p class="dts-section-description">Client paper submissions list the offices the client chose as “{{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}”. They all receive the document once you press Paper received.</p>
                        @endif
                    </div>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                        <span class="dts-count">{{ $officeQueueCount }} {{ Str::plural('action', $officeQueueCount) }}</span>
                    </div>
                </div>
                @php $queueVisibleRows = 5; @endphp
                <div class="dts-table-frame">
                    <div class="dts-table-scroll">
                      <table class="dts-table dts-office-queue-table">
                        <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Offices and status</th><th scope="col">Due date</th><th scope="col">Next action</th></tr></thead>
                        <tbody>
                            @forelse ($officeQueue as $queueItem)
                                @php
                                    $document = $queueItem['document'];
                                    $queueOffices = $queueItem['offices'];
                                    $singleOffice = $queueOffices->count() === 1 ? $queueOffices->first()['office'] : null;
                                    $isOverdue = $document->due_at !== null && $document->due_at->toDateString() < today('Asia/Manila')->toDateString();
                                    $queueDocumentUrl = route('platform.documents.view', array_filter(['document' => $document, 'source_office' => $singleOffice]));
                                    $queueWorkspaceUrl = route('platform.documents.workspace', $document);
                                    $awaitsPaper = $queueOffices->contains('status', \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT);
                                    $waitingOffices = $awaitsPaper ? $document->pendingRouteOffices() : collect();
                                    // Why the document is in the queue, in plain words, under the action button.
                                    $queueReason = $awaitsPaper ? "Waiting for the client's paper copy" : match ($queueOffices->first()['status']) {
                                        \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT, \App\Models\DocumentMovement::STATUS_FORWARDED => 'Waiting to be received',
                                        \App\Models\DocumentMovement::STATUS_RETURNED => 'Returned for corrections',
                                        \App\Models\DocumentMovement::STATUS_ON_HOLD => 'On hold',
                                        default => 'Received. Next step needed.',
                                    };
                                @endphp
                                <tr data-office-queue-row @if ($loop->index >= $queueVisibleRows) data-office-queue-extra hidden @endif>
                                    <td><div class="dts-record-link"><a href="{{ $queueDocumentUrl }}" data-workspace-url="{{ $queueWorkspaceUrl }}" @if ($singleOffice) data-workspace-office="{{ $singleOffice }}" @endif><strong>{{ $document->tracking_number }}</strong></a><span>{{ $document->subject }}</span></div></td>
                                    <td>
                                        <div class="dts-office-status-list">
                                            @foreach ($queueOffices as $queueOffice)
                                                {{-- The Records Unit's holding row is replaced by the client's offices below. --}}
                                                @continue($waitingOffices->isNotEmpty() && $queueOffice['status'] === \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
                                                <a class="dts-office-status-item" data-office-queue-office href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $queueOffice['office']]) }}" data-workspace-url="{{ $queueWorkspaceUrl }}" data-workspace-office="{{ $queueOffice['office'] }}" aria-label="View {{ $queueOffice['office'] }} workflow, {{ $queueOffice['status'] }}">
                                                    <span class="dts-office-status-name">{{ $queueOffice['office'] }}</span>
                                                    <span class="dts-record-status" data-status="{{ str($queueOffice['status'])->lower()->replace(' ', '-') }}">{{ $queueOffice['status'] }}</span>
                                                </a>
                                            @endforeach
                                            @if ($awaitsPaper)
                                                @foreach ($waitingOffices as $waitingOffice)
                                                    <a class="dts-office-status-item" data-office-queue-office href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $waitingOffice]) }}" data-workspace-url="{{ $queueWorkspaceUrl }}" data-workspace-office="{{ $waitingOffice }}" aria-label="View {{ $waitingOffice }} workflow, {{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}">
                                                        <span class="dts-office-status-name">{{ $waitingOffice }}</span>
                                                        <span class="dts-record-status" data-status="waiting-for-paper">{{ \App\Models\Document::ROUTE_WAITING_FOR_PAPER }}</span>
                                                    </a>
                                                @endforeach
                                            @endif
                                        </div>
                                    </td>
                                    <td><span @class(['dts-due-date', 'is-overdue' => $isOverdue])>{{ $document->due_at?->format('M d, Y') ?? 'No due date' }}</span></td>
                                    <td>
                                        @if (($canConfirmPaperIntake ?? false) && $awaitsPaper)
                                            <button type="button" class="dts-button dts-button-primary dts-paper-received" data-paper-received="{{ $document->tracking_number }}" data-lookup-url="{{ route('platform.documents.intake') }}"><x-orchid-icon path="bs.check2-circle" /> Paper received</button>
                                        @else
                                            <a class="dts-table-action" href="{{ $queueDocumentUrl }}" data-workspace-url="{{ $queueWorkspaceUrl }}" @if ($singleOffice) data-workspace-office="{{ $singleOffice }}" @endif>{{ $queueOffices->first()['action'] }} <x-orchid-icon path="bs.chevron-right" /></a>
                                        @endif
                                        <small class="dts-queue-reason">{{ $queueReason }}</small>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><div class="dts-empty dts-empty-compact">
                                    <span class="dts-empty-icon"><x-orchid-icon path="bs.check2-circle" /></span>
                                    <h4>No pending office actions</h4>
                                    <p>{{ $workspaceOffice === 'No office assigned' ? 'Ask an administrator to assign your account to an office.' : 'There are no documents waiting for action in this workspace.' }}</p>
                                </div></td></tr>
                            @endforelse
                        </tbody>
                      </table>
                    </div>
                </div>
                @php $queueHiddenRows = max(0, $officeQueue->count() - $queueVisibleRows); @endphp
                @if ($queueHiddenRows > 0 || $officeQueueDocumentCount > $officeQueue->count())
                    <div class="dts-queue-more">
                        @if ($queueHiddenRows > 0)
                            <button type="button" class="dts-button dts-button-secondary" data-office-queue-more><x-orchid-icon path="bs.chevron-down" /> Show {{ $queueHiddenRows }} more</button>
                        @endif
                        @if ($officeQueueDocumentCount > $officeQueue->count())
                            <a href="{{ route('platform.documents') }}" data-office-queue-all @if ($queueHiddenRows > 0) hidden @endif>See all {{ $officeQueueDocumentCount }} documents in the registry <x-orchid-icon path="bs.chevron-right" /></a>
                        @endif
                    </div>
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
                                <td><div class="dts-record-link"><a href="{{ route('platform.documents.view', $document) }}" data-workspace-url="{{ route('platform.documents.workspace', $document) }}"><strong>{{ $document->tracking_number }}</strong></a><span>{{ $document->subject }}</span></div></td>
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
            <aside class="dts-activity" aria-label="Document activity">
                <div class="dts-sidebar-heading">
                    <h3>Notifications</h3>
                    <div class="dts-notification-heading-actions">
                        @if ($unreadNotificationCount > 0)
                            <span class="dts-count">{{ $unreadNotificationCount }} unread</span>
                            <button type="submit" class="dts-notification-read-all" formaction="{{ route('platform.notifications.read-all') }}" formmethod="POST" formnovalidate aria-label="Mark all notifications as read"><x-orchid-icon path="bs.check2-all" /> Mark all read</button>
                        @endif
                    </div>
                </div>
                @forelse ($unreadNotifications as $notification)
                    <a class="dts-notification" href="{{ route('platform.notifications.open', $notification->id) }}">
                        @php
                            $notificationEvent = data_get($notification->data, 'event', data_get($notification->data, 'action'));
                            [$notificationTone, $notificationIcon] = match ($notificationEvent) {
                                'overdue', 'health_critical' => ['is-overdue', 'bs.exclamation-triangle-fill'],
                                'due_soon', 'health_warning' => ['is-due-soon', 'bs.clock-fill'],
                                'health_recovered' => ['is-recovered', 'bs.check-circle-fill'],
                                default => ['', 'bs.bell'],
                            };
                        @endphp
                        <span class="dts-notification-icon {{ $notificationTone }}"><x-orchid-icon :path="$notificationIcon" /></span>
                        <span><strong>{{ $notification->data['tracking_number'] ?? $notification->data['title'] ?? 'Document update' }}</strong><small>{{ $notification->data['message'] ?? 'A document was assigned to your office.' }}</small><small>{{ $notification->created_at->diffForHumans() }}</small></span>
                    </a>
                @empty
                    <div class="dts-activity-empty"><x-orchid-icon path="bs.bell" /><p>No unread notifications.</p></div>
                @endforelse
                <h3>Recent activity</h3>
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
            </aside>
        @endif
    </div>
</div>
@include('orchid.partials.document-workspace-dialog')
