<div class="dts-workspace dts-registry-page">
    <div class="dts-masthead tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-4">
        <div>
            <h2>{{ match ($section) { 'incoming' => 'Incoming documents', 'outgoing' => 'Outgoing documents', 'archived' => 'Document archive', default => 'Document registry' } }}</h2>
        </div>
        @unless (request()->user()?->isReadOnlySupervisor())
            <a class="dts-button dts-button-primary" href="{{ route('platform.documents.create') }}"><x-orchid-icon path="bs.plus-lg" /> New document</a>
        @endunless
    </div>

    <div class="visually-hidden" data-registry-live role="status" aria-live="polite"></div>
    <turbo-frame id="document-registry" data-turbo-action="advance" data-bulk-label-url="{{ route('platform.documents.labels') }}" @if (request()->user()?->isAdministrator()) data-bulk-delete-url="{{ route('platform.documents.bulk-destroy') }}" @endif>
        <div class="dts-registry-feedback dts-registry-loading" aria-hidden="true"><span class="dts-registry-spinner"></span> Updating results</div>
        <div class="dts-registry-feedback dts-registry-request-error" data-registry-request-error role="alert" hidden>
            <span>Could not update results. The current table is still shown.</span>
            <button type="button" class="dts-button dts-button-secondary" data-registry-retry>Retry</button>
        </div>
        <section class="dts-registry" aria-labelledby="registry-title">
            <h3 id="registry-title" class="visually-hidden">Document results</h3>
            <div class="dts-registry-tabs-row">
                <nav class="dts-tabs" aria-label="Document views">
                    @foreach (['documents' => 'All documents', 'incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'archived' => 'Archived'] as $key => $label)
                        <a href="{{ route('platform.'.$key) }}" data-turbo-frame="_top" @class(['dts-tab', 'is-active' => $section === $key]) @if ($section === $key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
                <span class="dts-count">{{ $documentCount }} {{ Str::plural('record', $documentCount) }}</span>
            </div>

            <dts-document-filters class="dts-registry-controls" data-section="{{ $section }}">
                {!! $controls !!}
                <div class="dts-registry-table-toolbar">
                    @include('orchid.partials.status-guide')
                    @include('orchid.partials.column-menu')
                    {!! $pageSize !!}
                </div>
            </dts-document-filters>
            {!! $table !!}
        </section>
    </turbo-frame>
    @include('orchid.partials.document-workspace-dialog')
    @if (request()->integer('open_document') > 0)
        <button type="button" hidden data-workspace-auto-open data-workspace-url="{{ route('platform.documents.workspace', request()->integer('open_document')) }}" data-workspace-office="{{ request()->query('source_office') }}" data-workspace-return-url="{{ route('platform.documents') }}"></button>
    @endif
</div>
