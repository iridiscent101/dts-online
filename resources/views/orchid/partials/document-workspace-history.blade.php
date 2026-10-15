@forelse ($history as $event)
    @php
        $isMovement = $event instanceof \App\Models\DocumentMovement;
        $badge = $isMovement ? \App\Support\StatusLabel::for($event->to_status) : match ($event->description) {
            'Remark added' => 'Remark',
            'Document registered', 'Document submitted' => 'Registered',
            default => 'Update',
        };
        $eventRemark = $isMovement ? $event->remarks : data_get($event->properties, 'remark');
    @endphp
    <article class="dts-workspace-entry dts-workspace-timeline-entry" tabindex="-1" data-workspace-history-event data-history-from="{{ $isMovement ? $event->from_office : data_get($event->properties, 'from_office', data_get($event->properties, 'office')) }}" data-history-to="{{ $isMovement ? $event->to_office : data_get($event->properties, 'to_office', data_get($event->properties, 'office')) }}">
        <div class="dts-workspace-entry-heading"><div class="dts-workspace-event-title"><strong>{{ $isMovement ? $event->title() : $event->description }}</strong><span class="dts-record-status" data-status="{{ str($badge)->lower()->replace(' ', '-') }}">{{ $badge }}</span></div><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->timezone('Asia/Manila')->format('M d, Y · g:i A') }}</time></div>
        <p class="dts-muted">{{ $isMovement ? ($event->actor?->name ?? 'System').' · '.($event->from_office ?: $event->to_office) : ($event->causer?->name ?? 'System').(filled(data_get($event->properties, 'office')) ? ' · '.data_get($event->properties, 'office') : '') }}</p>
        @if (filled($eventRemark))
            <details class="dts-workspace-history-remarks"><summary>View remarks</summary><p>{{ $eventRemark }}</p></details>
        @endif
        @if (! $isMovement && $event->description === 'Document details updated')
            @include('orchid.partials.document-change-details', ['activity' => $event])
        @endif
    </article>
@empty
    @if ($historyPage === 1)<p class="dts-workspace-empty">{{ $historyOffice ? 'No activity for this route yet.' : 'No activity recorded.' }}</p>@endif
@endforelse
@if ($historyHasMore || ($historyPage > 1 && $historyTotal > 0))
    {{-- Replaced by the next page's footer when older activity is loaded (loadWorkspaceHistory in dts.js). --}}
    <div class="dts-workspace-history-footer" data-workspace-history-footer>
        <p class="dts-muted" role="status">{{ $historyHasMore ? 'Showing '.number_format($historyShown).' of '.number_format($historyTotal).' entries' : 'All '.number_format($historyTotal).' entries shown' }}</p>
        @if ($historyHasMore)
            <button type="button" class="dts-button dts-button-secondary dts-workspace-history-more" data-workspace-history-more data-history-page="{{ $historyPage + 1 }}">Load older activity</button>
        @endif
    </div>
@endif
