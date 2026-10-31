{{-- "Status guide" for staff: the statuses the current workflow uses (Receive, Forward, Complete, Release), in the
     order a document goes through them, each as its badge with a one-line meaning. --}}
@php
    $staffMeanings = [
        // "Pending" covers both waiting statuses; the list under it names each kind.
        \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT => 'Still waiting on a step. It can mean:',
        \App\Models\DocumentMovement::STATUS_RECEIVED => 'The office has it. Next: forward, complete or (Records Unit) release it.',
        \App\Models\DocumentMovement::STATUS_FORWARDED => 'Sent on to the next office. That office still has to receive it.',
        \App\Models\DocumentMovement::STATUS_COMPLETED => 'The office has finished its part.',
        \App\Models\DocumentMovement::STATUS_RELEASED => 'The Records Unit released it to the client. With several offices, only the part that was ready is released; the others continue.',
    ];
    $pendingKinds = [
        'Awaiting receipt' => 'sent to the office, which has not received it yet.',
        'Waiting for physical document/s' => 'a client submitted it online and the physical document/s are not at the Records Unit yet.',
    ];
@endphp
<details class="dts-status-guide">
    <summary class="dts-button dts-icon-only" data-tooltip="Status guide"><x-orchid-icon path="bs.question-circle" /><span class="visually-hidden">Status guide</span></summary>
    <div class="dts-status-guide-panel">
        <strong>What each status means</strong>
        <dl>
            @foreach ($staffMeanings as $status => $meaning)
                <div>
                    <dt><span class="dts-record-status" data-status="{{ str($status)->lower()->replace(' ', '-') }}">{{ \App\Support\StatusLabel::for($status) }}</span></dt>
                    <dd>
                        {{ $meaning }}
                        @if ($status === \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT)
                            <ul class="dts-status-guide-kinds">
                                @foreach ($pendingKinds as $kind => $kindMeaning)
                                    <li><strong>{{ $kind }}:</strong> {{ $kindMeaning }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</details>
