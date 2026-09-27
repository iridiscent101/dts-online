<div class="dts-workspace">
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <p class="dts-eyebrow">DEPARTMENT OF EDUCATION</p>
            <h2>{{ match ($section) { 'incoming' => 'Incoming documents', 'outgoing' => 'Outgoing documents', 'archived' => 'Document archive', 'documents' => 'Document registry', default => 'Document overview' } }}</h2>
            <p class="dts-muted">Records, correspondence, and office transactions.</p>
        </div>
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-4">
            <span class="dts-date"><x-orchid-icon path="bs.calendar3" /> {{ now()->timezone('Asia/Manila')->format('D, d M Y') }}</span>
            <a class="dts-button dts-button-primary" href="{{ route('platform.documents.create') }}"><x-orchid-icon path="bs.plus-lg" /> New document</a>
        </div>
    </div>
    @if ($section === 'dashboard')
        <div class="tw:grid tw:grid-cols-1 tw:gap-4 tw:sm:grid-cols-2 tw:xl:grid-cols-4 dts-metrics">
            @foreach ([
                ['All documents', 'files', 'blue', 'documents', 'Total registered records'],
                ['Incoming', 'inbox', 'amber', 'incoming', 'Awaiting receipt'],
                ['Outgoing', 'send', 'red', 'outgoing', 'Forwarded to other offices'],
                ['Archived', 'archive', 'green', 'archived', 'Completed and filed'],
            ] as [$label, $icon, $color, $destination, $caption])
                <a class="dts-metric dts-{{ $color }}" href="{{ route('platform.'.$destination) }}">
                    <div class="tw:flex tw:items-center tw:justify-between tw:gap-2"><span>{{ $label }}</span><span class="dts-icon"><x-orchid-icon :path="'bs.'.$icon" /></span></div>
                    <strong>{{ $documentCounts[$destination] }}</strong><small>{{ $caption }}</small>
                </a>
            @endforeach
        </div>
    @endif
    <div class="tw:grid tw:grid-cols-1 tw:gap-8 {{ $section === 'dashboard' ? 'tw:xl:grid-cols-[minmax(0,1fr)_260px]' : '' }}">
        <section class="dts-registry tw:min-w-0" aria-labelledby="registry-title">
            <div class="dts-office-queue" aria-labelledby="office-queue-title">
                <div class="dts-section-heading tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
                    <div>
                        <h3 id="office-queue-title">{{ $workspaceOffice === 'All offices' ? 'Action queue' : 'My office queue' }}</h3>
                        <p class="dts-section-caption">{{ $workspaceOffice }}</p>
                    </div>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                        @if ($officeOverdueCount > 0)<span class="dts-count dts-count-danger">{{ $officeOverdueCount }} overdue</span>@endif
                        <span class="dts-count">{{ $officeQueueCount }} {{ Str::plural('action', $officeQueueCount) }}</span>
                    </div>
                </div>
                <div class="dts-table-frame">
                    <div class="dts-table-scroll">
                      <table class="dts-table dts-office-queue-table">
                        <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Status</th><th scope="col">Due date</th><th scope="col">Next action</th></tr></thead>
                        <tbody>
                            @forelse ($officeQueue as $document)
                                @php($isOverdue = $document->due_at?->isBefore(today()) ?? false)
                                <tr>
                                    <td><div class="dts-record-link"><a href="{{ route('platform.documents.view', $document) }}"><strong>{{ $document->tracking_number }}</strong></a><span>{{ $document->subject }}</span></div></td>
                                    <td><span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}">{{ $document->status }}</span></td>
                                    <td><span @class(['dts-due-date', 'is-overdue' => $isOverdue])>{{ $document->due_at?->format('M d, Y') ?? 'No due date' }}</span></td>
                                    <td><a class="dts-table-action" href="{{ route('platform.documents.view', $document) }}">{{ match ($document->status) { 'Awaiting receipt', 'Forwarded' => 'Acknowledge', 'Received' => 'Process', default => 'View' } }} <x-orchid-icon path="bs.chevron-right" /></a></td>
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
            </div>
            <div class="dts-section-heading tw:flex tw:items-center tw:justify-between tw:gap-4"><h3 id="registry-title">Recent documents</h3><span class="dts-count">{{ $recentDocuments->count() }} {{ Str::plural('record', $recentDocuments->count()) }}</span></div>
            <nav class="dts-tabs" aria-label="Document views">
                @foreach (['documents' => 'All documents', 'incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'archived' => 'Archived'] as $key => $label)
                    <a href="{{ route('platform.'.$key) }}" @class(['dts-tab', 'is-active' => $section === $key || ($section === 'dashboard' && $key === 'documents')]) @if ($section === $key || ($section === 'dashboard' && $key === 'documents')) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="dts-table-frame">
              <div class="dts-table-scroll">
                <table class="dts-table dts-recent-documents-table">
                    <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Office</th><th scope="col">Status</th><th scope="col">Last updated</th></tr></thead>
                    <tbody>
                        @forelse ($recentDocuments as $document)
                            <tr>
                                <td><div class="dts-record-link"><a href="{{ route('platform.documents.view', $document) }}"><strong>{{ $document->tracking_number }}</strong></a><span>{{ $document->subject }}</span></div></td>
                                <td>{{ $document->current_office }}</td>
                                <td><span class="dts-record-status" data-status="{{ str($document->status)->lower()->replace(' ', '-') }}">{{ $document->status }}</span></td>
                                <td>{{ $document->updated_at->format('M d, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="dts-empty">
                                <span class="dts-empty-icon"><x-orchid-icon path="bs.folder2-open" /></span>
                                <h4>No documents registered yet</h4>
                                <p>Your document registry is currently empty.</p>
                            </div></td></tr>
                        @endforelse
                    </tbody>
                </table>
              </div>
              <div class="dts-table-footer">{{ $documentCounts['documents'] }} {{ Str::plural('document', $documentCounts['documents']) }}</div>
            </div>
        </section>
        @if ($section === 'dashboard')
            <aside class="dts-activity" aria-label="Document activity">
                <h3>Office workspace</h3>
                <div class="dts-office"><span class="dts-icon"><x-orchid-icon path="bs.building" /></span><div><strong>{{ $workspaceOffice }}</strong><small>Document operations</small></div></div>
                <div class="dts-sidebar-heading"><h3>Notifications</h3>@if ($unreadNotificationCount > 0)<span class="dts-count">{{ $unreadNotificationCount }} unread</span>@endif</div>
                @forelse ($unreadNotifications as $notification)
                    <a class="dts-notification" href="{{ route('platform.notifications.open', $notification->id) }}">
                        <span class="dts-notification-icon"><x-orchid-icon path="bs.bell" /></span>
                        <span><strong>{{ $notification->data['tracking_number'] ?? 'Document update' }}</strong><small>{{ $notification->data['message'] ?? 'A document was assigned to your office.' }}</small><small>{{ $notification->created_at->diffForHumans() }}</small></span>
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
                                <strong><a href="{{ route('platform.documents.view', $movement->document) }}">{{ $movement->title() }}</a></strong>
                                <small>{{ $movement->document->tracking_number }}</small>
                                <small>{{ $movement->actor?->name ?? 'System' }} &middot; {{ $movement->created_at->diffForHumans() }}</small>
                            </div>
                        </li>
                    @if ($loop->last)</ol>@endif
                @empty
                    <div class="dts-activity-empty"><x-orchid-icon path="bs.clock-history" /><p>No document activity yet.</p></div>
                @endforelse
                <div class="dts-section-heading"><h3>Document queues</h3></div>
                @foreach ([['incoming', 'amber', 'Awaiting receipt'], ['outgoing', 'red', 'Forwarded'], ['archived', 'green', 'Archived']] as [$destination, $color, $label])
                    <a class="dts-queue" href="{{ route('platform.'.$destination) }}"><span><i class="dts-dot {{ $color }}"></i>{{ $label }}</span><strong>{{ $documentCounts[$destination] }} <x-orchid-icon path="bs.chevron-right" /></strong></a>
                @endforeach
            </aside>
        @endif
    </div>
</div>
