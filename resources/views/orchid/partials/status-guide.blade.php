{{-- "Status guide" for staff: the statuses the current workflow uses (Receive, Forward, Complete, Release), in the
     order a document goes through them, each as its badge with a one-line meaning. --}}
@php
    $staffMeanings = [
        // "Pending" covers both waiting statuses; the list under it names each kind.
        \App\Models\DocumentMovement::STATUS_AWAITING_RECEIPT => 'Waiting for:',
        \App\Models\DocumentMovement::STATUS_RECEIVED => 'Office has it; ready for the next action.',
        \App\Models\DocumentMovement::STATUS_FORWARDED => 'Sent to the next office; awaiting receipt.',
        \App\Models\DocumentMovement::STATUS_COMPLETED => 'Office finished its part.',
        \App\Models\DocumentMovement::STATUS_RELEASED => 'Records Unit gave it to the client. Other offices may still be working.',
    ];
    $pendingKinds = [
        'Awaiting receipt' => 'the office to receive it.',
        'Waiting for physical document/s' => 'the paper copy to reach Records Unit.',
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
