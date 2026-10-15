<div class="dts-record-link">
    <div class="dts-record-code">
        <a href="{{ route('platform.documents.view', $document) }}" data-workspace-url="{{ route('platform.documents.workspace', $document) }}" data-turbo-frame="_top"><strong>{{ $document->tracking_number }}</strong></a>
        @include('orchid.partials.copy-button', ['text' => $document->tracking_number])
    </div>
    <span>{{ $document->subject }}</span>
</div>
