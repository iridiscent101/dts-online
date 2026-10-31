{{-- Laid out like the Fast Track "Document movement" timeline: a day heading, a colored step icon on a line, the
     step title, then From / destination / status / date / who, and the remarks in full. --}}
@php
    $historyIcons = ['moved' => 'bs.arrow-right', 'received' => 'bs.box-arrow-in-down', 'done' => 'bs.check-lg', 'hold' => 'bs.pause-fill', 'returned' => 'bs.arrow-return-left', 'cancelled' => 'bs.slash-circle', 'note' => 'bs.pencil-square', 'remark' => 'bs.chat-left-text', 'registered' => 'bs.file-earmark-check', 'signed' => 'bs.pen'];
    $historyToday = now('Asia/Manila');
    $previousDay = null;
@endphp
@forelse ($history as $event)
    @php
        $isMovement = $event instanceof \App\Models\DocumentMovement;
        // A client's "Instructions for Records" are kept on the automatic first "sent" step; show them on "Document submitted".
        $eventRemark = match (true) {
            $isMovement => $document->isClientSubmissionStep($event) ? null : $event->remarks,
            $event->description === 'Document submitted' => $document->clientInstructions(),
            // Route corrections and added offices keep the reason given for them.
            in_array($event->description, ['Document route corrected', 'Document route added', 'Document route deleted'], true) => data_get($event->properties, 'reason', data_get($event->properties, 'correction_reason', data_get($event->properties, 'deletion_reason'))),
            default => data_get($event->properties, 'remark'),
        };
        $eventRemarkLabel = match (true) {
            $isMovement => 'Remarks',
            $event->description === 'Document submitted' => 'Instructions for Records',
            in_array($event->description, ['Document route corrected', 'Document route added', 'Document route deleted'], true) => 'Reason',
            default => 'Remarks',
        };
        $eventKind = $isMovement ? $event->publicKind() : match ($event->description) {
            'Remark added' => 'remark',
            'Document registered', 'Document submitted' => 'registered',
            'Release signed' => 'signed',
            default => 'note',
        };
        $eventAt = $event->created_at?->timezone('Asia/Manila');
        $eventDay = $eventAt?->toDateString();
        $eventFrom = $isMovement ? $event->from_office : data_get($event->properties, 'from_office', data_get($event->properties, 'office'));
        $eventTo = $isMovement ? $event->to_office : data_get($event->properties, 'to_office');
        $eventBy = $isMovement ? $event->actor?->name : ($eventKind === 'signed' ? data_get($event->properties, 'signed_by') : $event->causer?->name);
        // Correct route and Add office route: say which office changed, not just where it went.
        $routeChange = match ($isMovement ? null : $event->description) {
            'Document route corrected' => ['Route corrected', ['Sending office' => $eventFrom, 'Wrong office' => data_get($event->properties, 'old_destination'), 'Corrected to' => data_get($event->properties, 'new_destination', $eventTo)]],
            'Document route added' => ['Office added', ['Sending office' => $eventFrom, 'Office added' => $eventTo]],
            'Document route deleted' => ['Route deleted', ['Sending office' => $eventFrom, 'Deleted office' => data_get($event->properties, 'deleted_office')]],
            default => null,
        };
    @endphp
    @if ($eventDay !== $previousDay)
        {{-- loadWorkspaceHistory (dts.js) drops a repeated heading when an older page starts on the same day. --}}
        <h4 class="dts-workspace-history-day" data-history-day="{{ $eventDay }}">{{ $eventAt?->isSameDay($historyToday) ? 'Today' : ($eventAt?->isSameDay($historyToday->copy()->subDay()) ? 'Yesterday' : $eventAt?->format('F j, Y')) }}</h4>
        @php($previousDay = $eventDay)
    @endif
    <article class="dts-workspace-entry dts-workspace-timeline-entry" data-kind="{{ $eventKind }}" tabindex="-1" data-workspace-history-event data-history-from="{{ $eventFrom }}" data-history-to="{{ $isMovement ? $event->to_office : data_get($event->properties, 'to_office', data_get($event->properties, 'office')) }}">
        <span class="dts-timeline-dot" aria-hidden="true"><x-orchid-icon :path="$historyIcons[$eventKind] ?? 'bs.dot'" /></span>
        <div class="dts-workspace-timeline-body">
            <strong class="dts-workspace-event-title">{{ $isMovement ? $event->title() : ($routeChange[0] ?? ($eventKind === 'signed' ? 'Signed for by the recipient' : $event->description)) }}@if ($historyPage === 1 && $loop->first) <span class="dts-timeline-latest">Latest</span>@endif</strong>
            <dl class="dts-workspace-step-details">
                @if ($routeChange)
                    @foreach (array_filter($routeChange[1], 'filled') as $routeLabel => $routeOffice)
                        <dt>{{ $routeLabel }}</dt><dd>{{ $routeOffice }}</dd>
                    @endforeach
                {{-- "From" only when the step moved the document; a step an office took on its own copy names that office. --}}
                @elseif (filled($eventFrom))
                    <dt>{{ filled($eventTo) && $eventTo !== $eventFrom ? 'From' : 'Office' }}</dt><dd>{{ $eventFrom }}</dd>
                @endif
                @if (! $routeChange && filled($eventTo) && $eventTo !== $eventFrom)
                    <dt>Office destination</dt><dd>{{ $eventTo }}</dd>
                @endif
                @if ($isMovement && filled($event->to_status))
                    <dt>Status</dt><dd><span class="dts-record-status" data-status="{{ str($event->to_status)->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($event->to_status) }}>{{ \App\Support\StatusLabel::for($event->to_status) }}</span></dd>
                @endif
                @if ($isMovement && $document->client_submission_key && $event->action === \App\Models\DocumentMovement::ACTION_SENT && $event->from_office === null && filled($document->delivery_method))
                    <dt>Mode of submission</dt><dd>{{ $document->delivery_method }}</dd>
                @endif
                <dt>Date / time</dt><dd><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $eventAt?->format('M d, Y') }}<span class="dts-step-time-separator" aria-hidden="true">|</span>{{ $eventAt?->format('g:i A') }}</time></dd>
                {{-- A remark already names its writer ("Name: remark") below, so it gets no "Added by" row. --}}
                @unless ($eventKind === 'remark')
                    <dt>{{ $eventKind === 'signed' ? 'Signed by' : 'Processed by' }}</dt><dd>{{ $eventBy ?? ($eventKind === 'signed' ? 'The recipient' : 'System') }}</dd>
                @endunless
                @if ($eventKind === 'signed')
                    <dt>Signed on</dt><dd>{{ data_get($event->properties, 'signed_on', 'Fast Track page') }}</dd>
                @endif
                @if ($isMovement && $event->released_to)
                    <dt>Released to</dt><dd>{{ $event->released_to }}</dd>
                @elseif ($isMovement && $event->action === \App\Models\DocumentMovement::ACTION_RELEASED)
                    <dt>Released to</dt><dd>Not recorded</dd>
                @endif
                @if ($isMovement && $event->action === \App\Models\DocumentMovement::ACTION_RELEASED && filled($event->recipient_signature))
                    <dt>Signature</dt><dd><img class="dts-history-signature" src="{{ $event->recipient_signature }}" alt="Signature of {{ $event->released_to }}" loading="lazy"></dd>
                @elseif ($isMovement && $event->action === \App\Models\DocumentMovement::ACTION_RELEASED)
                    <dt>Signature</dt><dd>Not signed</dd>
                @endif
            </dl>
            @if (filled($eventRemark))
                {{-- An added remark reads "Name: remark"; other steps keep a heading (Remarks, Reason, Instructions). --}}
                <div class="dts-workspace-history-remarks">@if ($eventKind === 'remark')<p><strong>{{ $eventBy ?? 'Staff' }}:</strong> {{ $eventRemark }}</p>@else<strong>{{ $eventRemarkLabel }}</strong><p>{{ $eventRemark }}</p>@endif</div>
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
