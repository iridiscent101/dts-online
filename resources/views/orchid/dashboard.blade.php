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
            <div class="dts-section-heading tw:flex tw:items-center tw:justify-between tw:gap-4"><h3 id="registry-title">Recent documents</h3><span class="dts-count">{{ $recentDocuments->count() }} {{ Str::plural('record', $recentDocuments->count()) }}</span></div>
            <nav class="dts-tabs" aria-label="Document views">
                @foreach (['documents' => 'All documents', 'incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'archived' => 'Archived'] as $key => $label)
                    <a href="{{ route('platform.'.$key) }}" @class(['dts-tab', 'is-active' => $section === $key || ($section === 'dashboard' && $key === 'documents')]) @if ($section === $key || ($section === 'dashboard' && $key === 'documents')) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="dts-table-scroll">
                <table class="dts-table">
                    <thead><tr><th scope="col">Tracking no. / Subject</th><th scope="col">Office</th><th scope="col">Status</th><th scope="col">Last updated</th></tr></thead>
                    <tbody>
                        @forelse ($recentDocuments as $document)
                            <tr>
                                <td><div class="dts-record-link"><strong>{{ $document->tracking_number }}</strong><span>{{ $document->subject }}</span></div></td>
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
        </section>
        @if ($section === 'dashboard')
            <aside class="dts-activity" aria-label="Document activity">
                <h3>Office workspace</h3>
                <div class="dts-office"><span class="dts-icon"><x-orchid-icon path="bs.building" /></span><div><strong>Department of Education</strong><small>Records management</small></div></div>
                <h3>Recent activity</h3>
                <div class="dts-activity-empty"><x-orchid-icon path="bs.clock-history" /><p>No document activity yet.</p></div>
                <div class="dts-section-heading"><h3>Document queues</h3></div>
                @foreach ([['incoming', 'amber', 'Awaiting receipt'], ['outgoing', 'red', 'Forwarded'], ['archived', 'green', 'Archived']] as [$destination, $color, $label])
                    <a class="dts-queue" href="{{ route('platform.'.$destination) }}"><span><i class="dts-dot {{ $color }}"></i>{{ $label }}</span><strong>{{ $documentCounts[$destination] }} <x-orchid-icon path="bs.chevron-right" /></strong></a>
                @endforeach
            </aside>
        @endif
    </div>
</div>
