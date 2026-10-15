<div class="dts-tracking-label" aria-label="Tracking label for {{ $document->tracking_number }}">
    <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="120" height="120">
    <dl class="dts-tracking-label-details">
        <dt>Status</dt><dd>{{ \App\Support\StatusLabel::for($labelDetails['status']) }}</dd>
        <dt>Date</dt><dd>{{ $labelDetails['date'] }}</dd>
        <dt>Time</dt><dd>{{ $labelDetails['time'] }}</dd>
        <dt>By</dt><dd>{{ $labelDetails['processedBy'] }}</dd>
        {{-- The processing staff member's signature from their profile, when they have saved one. --}}
        @if (filled($labelDetails['processedBySignatureDataUri'] ?? null))
            <dt>Sign</dt><dd class="dts-tracking-label-signature"><img src="{{ $labelDetails['processedBySignatureDataUri'] }}" alt="Signature of {{ $labelDetails['processedBy'] }}"></dd>
        @endif
    </dl>
    <div class="dts-tracking-label-footer">
        <strong>{{ $document->tracking_number }}</strong>
        <small>Scan to track · SDO Nueva Vizcaya</small>
    </div>
</div>
