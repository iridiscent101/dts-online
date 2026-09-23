<dts-document-filters class="dts-registry-controls" data-section="{{ $section }}">
    <button type="button" class="dts-registry-toolbar" data-filter-toggle aria-expanded="true" aria-controls="registry-filter-panel">
        <span class="dts-registry-toolbar-copy">
            <strong>Find a document</strong>
            <span>Search the registry or narrow the results using the filters below.</span>
        </span>
        <span class="dts-registry-toggle-icon" aria-hidden="true"><x-orchid-icon path="bs.chevron-up" /></span>
    </button>
    <div id="registry-filter-panel" class="dts-registry-panel" data-filter-panel>
      <div class="dts-registry-filters">
        <div class="dts-field dts-search-field">
            <label for="registry-search">Search</label>
            <div class="dts-input-icon">
                <span class="dts-search-icon" aria-hidden="true"><x-orchid-icon path="bs.search" /></span>
                <input id="registry-search" data-filter="search" type="search" value="{{ $filterValues['search'] }}" placeholder="Tracking number or subject" autocomplete="off">
            </div>
        </div>
        <div class="dts-field dts-office-field">
            <label for="registry-office">Current office</label>
            <select id="registry-office" data-filter="office">
                <option value="">All offices</option>
                @foreach (\App\Models\Document::OFFICES as $office)
                    <option value="{{ $office }}" @selected($filterValues['office'] === $office)>{{ $office }}</option>
                @endforeach
            </select>
        </div>
        <div class="dts-field dts-type-field">
            <label for="registry-type">Document type</label>
            <select id="registry-type" data-filter="type">
                <option value="">All types</option>
                @foreach (\App\Models\Document::TYPES as $type)
                    <option value="{{ $type }}" @selected($filterValues['type'] === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="dts-date-range">
            <legend>Date received</legend>
            <div class="dts-date-range-fields">
                <div class="dts-field"><label for="registry-from">From</label><input id="registry-from" data-filter="from" type="date" value="{{ $filterValues['from'] }}"></div>
                <div class="dts-field"><label for="registry-to">To</label><input id="registry-to" data-filter="to" type="date" value="{{ $filterValues['to'] }}" aria-describedby="registry-error"></div>
            </div>
        </fieldset>
        @if ($section === 'documents')
            <div class="dts-field dts-status-field">
                <label for="registry-status">Status</label>
                <select id="registry-status" data-filter="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\Document::STATUSES as $status)
                        <option value="{{ $status }}" @selected($filterValues['status'] === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="dts-filter-actions">
            <button type="button" class="dts-button dts-reset-filters" data-filter-action="reset"><x-orchid-icon path="bs.arrow-counterclockwise" /> Reset</button>
            <button type="button" class="dts-button dts-apply-filters" data-filter-action="apply"><x-orchid-icon path="bs.funnel" /> Apply</button>
        </div>
      </div>
      <p id="registry-error" class="dts-validation" role="alert">{{ $filterError }}</p>
    </div>
</dts-document-filters>
