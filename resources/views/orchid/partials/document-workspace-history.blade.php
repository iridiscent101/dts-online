{{-- Laid out like the Fast Track "Document movement" timeline: a day heading, a colored step icon on a line, the
     step title, then From / destination / status / date / who, and the remarks in full. --}}
@php
    $historyIcons = ['moved' => 'bs.arrow-right', 'received' => 'bs.inbox', 'done' => 'bs.check-lg', 'hold' => 'bs.pause-fill', 'returned' => 'bs.arrow-return-left', 'cancelled' => 'bs.slash-circle', 'note' => 'bs.pencil-square', 'remark' => 'bs.chat-left-text', 'registered' => 'bs.file-earmark-check'];
    $historyToday = now('Asia/Manila');
    $previousDay = null;
@endphp
@forelse ($history as $event)
    @php
        $isMovement = $event instanceof \App\Models\DocumentMovement;
        $eventRemark = $isMovement ? $event->remarks : data_get($event->properties, 'remark');
        $eventKind = $isMovement ? $event->publicKind() : match ($event->description) {
            'Remark added' => 'remark',
            'Document registered', 'Document submitted' => 'registered',
            default => 'note',
        };
        $eventAt = $event->created_at?->timezone('Asia/Manila');
        $eventDay = $eventAt?->toDateString();
        $eventFrom = $isMovement ? $event->from_office : data_get($event->properties, 'from_office', data_get($event->properties, 'office'));
        $eventTo = $isMovement ? $event->to_office : data_get($event->properties, 'to_office');
        $eventBy = $isMovement ? $event->actor?->name : $event->causer?->name;
    @endphp
    @if ($eventDay !== $previousDay)
        {{-- loadWorkspaceHistory (dts.js) drops a repeated heading when an older page starts on the same day. --}}
        <h4 class="dts-workspace-history-day" data-history-day="{{ $eventDay }}">{{ $eventAt?->isSameDay($historyToday) ? 'Today' : ($eventAt?->isSameDay($historyToday->copy()->subDay()) ? 'Yesterday' : $eventAt?->format('F j, Y')) }}</h4>
        @php($previousDay = $eventDay)
    @endif
    <article class="dts-workspace-entry dts-workspace-timeline-entry" data-kind="{{ $eventKind }}" tabindex="-1" data-workspace-history-event data-history-from="{{ $eventFrom }}" data-history-to="{{ $isMovement ? $event->to_office : data_get($event->properties, 'to_office', data_get($event->properties, 'office')) }}">
        <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon :path="$historyIcons[$eventKind] ?? 'bs.dot'" /></span>
        <div class="dts-workspace-timeline-body">
            <strong class="dts-workspace-event-title">{{ $isMovement ? $event->title() : $event->description }}@if ($historyPage === 1 && $loop->first) <span class="dts-timeline-latest">Latest</span>@endif</strong>
            <dl class="dts-workspace-step-details">
                @if (filled($eventFrom))
                    <dt>From</dt><dd>{{ $eventFrom }}</dd>
                @endif
                @if (filled($eventTo) && $eventTo !== $eventFrom)
                    <dt>Office destination</dt><dd>{{ $eventTo }}</dd>
                @endif
                @if ($isMovement && filled($event->to_status))
                    <dt>Status</dt><dd><span class="dts-record-status" data-status="{{ str($event->to_status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($event->to_status) }}>{{ \App\Support\StatusLabel::for($event->to_status) }}</span></dd>
                @endif
                @if ($isMovement && $document->client_submission_key && $event->action === \App\Models\DocumentMovement::ACTION_SENT && $event->from_office === null && filled($document->delivery_method))
                    <dt>Mode of submission</dt><dd>{{ $document->delivery_method }}</dd>
                @endif
                <dt>Date / time</dt><dd><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $eventAt?->format('M d, Y') }}<span class="dts-step-time-separator" aria-hidden="true">|</span>{{ $eventAt?->format('g:i A') }}</time></dd>
                <dt>{{ $eventKind === 'remark' ? 'Added by' : 'Processed by' }}</dt><dd>{{ $eventBy ?? 'System' }}</dd>
                @if ($isMovement && $event->released_to)
                    <dt>Released to</dt><dd>{{ $event->released_to }}</dd>
                @endif
            </dl>
            @if (filled($eventRemark))
                <div class="dts-workspace-history-remarks"><strong>Remarks</strong><p>{{ $eventRemark }}</p></div>
            @endif
            @if (! $isMovement && $event->description === 'Document details updated')
                @include('orchid.partials.document-change-details', ['activity' => $event])
            @endif
        </div>
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
