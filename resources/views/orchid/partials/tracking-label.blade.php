<div class="dts-tracking-label" aria-label="Tracking label for {{ $document->tracking_number }}">
    <img src="{{ $qrCodeDataUri }}" alt="QR code for {{ $document->tracking_number }}" width="120" height="120">
    <div class="dts-tracking-label-copy">
        <span>DocuTrack · SDO – Nueva Vizcaya</span>
        <strong>{{ $document->tracking_number }}</strong>
        <small>Scan to track</small>
    </div>
</div>
