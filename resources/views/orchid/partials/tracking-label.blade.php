<div class="dts-tracking-label" aria-label="Tracking label for {{ $document->tracking_number }}">
    <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="120" height="120">
    <dl class="dts-tracking-label-details">
        <dt>Status</dt><dd class="dts-tracking-label-status">{{ \App\Support\StatusLabel::for($labelDetails['status']) }}</dd>
        <dt>Date</dt><dd>{{ $labelDetails['date'] }}</dd>
        <dt>Time</dt><dd>{{ $labelDetails['time'] }}</dd>
        <dt>{{ $labelDetails['byLabel'] }}</dt><dd>{{ $labelDetails['processedBy'] }}</dd>
    </dl>
    <div class="dts-tracking-label-footer">
        <strong>{{ $document->tracking_number }}</strong>
    </div>
</div>
