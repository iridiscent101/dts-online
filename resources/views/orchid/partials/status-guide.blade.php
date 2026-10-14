{{-- "Status guide" for staff: every status as its badge with a one-line meaning (staff wording of STATUS_MEANINGS). --}}
@php
    $staffMeanings = [
        ...\App\Models\DocumentMovement::STATUS_MEANINGS,
        \App\Models\DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'A client submitted it online. Waiting for their paper copy at the Records Unit.',
        \App\Models\DocumentMovement::STATUS_REJECTED => 'The office did not approve the document.',
    ];
@endphp
<details class="dts-status-guide">
    <summary class="dts-button"><x-orchid-icon path="bs.question-circle" /> Status guide</summary>
    <div class="dts-status-guide-panel">
        <strong>What each status means</strong>
        <dl>
            @foreach ($staffMeanings as $status => $meaning)
                <div>
                    <dt><span class="dts-record-status" data-status="{{ str($status)->lower()->replace(' ', '-') }}">{{ $status }}</span></dt>
                    <dd>{{ $meaning }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</details>
