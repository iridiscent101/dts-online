@php($releasedBy = $labelDetails['releasedBy'] ?? null)
<div class="dts-tracking-label" aria-label="Tracking label for {{ $document->tracking_number }}">
    <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="120" height="120">
    {{-- A release label carries two names, so each is kept to one line (has-release) to fit the 30 mm height. --}}
    <dl @class(['dts-tracking-label-details', 'has-release' => filled($releasedBy)])>
        {{-- Each caption sits above its value, so words like "RECEIVED" never split; date and time share a row. --}}
        <div class="dts-tracking-label-field is-wide"><dt>Status</dt><dd class="dts-tracking-label-status">{{ \App\Support\StatusLabel::for($labelDetails['status']) }}</dd></div>
        <div class="dts-tracking-label-field"><dt>Date</dt><dd>{{ $labelDetails['date'] }}</dd></div>
        <div class="dts-tracking-label-field"><dt>Time</dt><dd>{{ $labelDetails['time'] }}</dd></div>
        <div class="dts-tracking-label-field is-wide is-name"><dt>{{ $labelDetails['byLabel'] }}</dt><dd>{{ $labelDetails['processedBy'] }}</dd></div>
        @if (filled($releasedBy))
            <div class="dts-tracking-label-field is-wide is-name"><dt>Released by</dt><dd>{{ $releasedBy }}</dd></div>
        @endif
    </dl>
    <div class="dts-tracking-label-footer">
        <strong>{{ $document->tracking_number }}</strong>
    </div>
</div>
