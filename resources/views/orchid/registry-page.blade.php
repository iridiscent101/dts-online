<div class="dts-workspace dts-registry-page">
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <p class="dts-eyebrow">DEPARTMENT OF EDUCATION</p>
            <h2>{{ match ($section) { 'incoming' => 'Incoming documents', 'outgoing' => 'Outgoing documents', 'archived' => 'Document archive', default => 'Document registry' } }}</h2>
            <p class="dts-muted">Records, correspondence, and office transactions.</p>
        </div>
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-4">
            <span class="dts-date"><x-orchid-icon path="bs.calendar3" /> {{ now()->timezone('Asia/Manila')->format('D, d M Y') }}</span>
            <a class="dts-button dts-button-primary" href="{{ route('platform.documents.create') }}"><x-orchid-icon path="bs.plus-lg" /> New document</a>
        </div>
    </div>

    <turbo-frame id="document-registry" data-turbo-action="advance">
        <section class="dts-registry" aria-labelledby="registry-title">
            <div class="dts-section-heading tw:flex tw:items-center tw:justify-start tw:gap-3">
                <h3 id="registry-title">Documents</h3>
                <span class="dts-count">{{ $documentCount }} {{ Str::plural('record', $documentCount) }}</span>
            </div>
            <nav class="dts-tabs" aria-label="Document views">
                @foreach (['documents' => 'All documents', 'incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'archived' => 'Archived'] as $key => $label)
                    <a href="{{ route('platform.'.$key) }}" data-turbo-frame="_top" @class(['dts-tab', 'is-active' => $section === $key]) @if ($section === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            {!! $controls !!}
            {!! $pageSize !!}
            {!! $table !!}
        </section>
    </turbo-frame>
</div>
