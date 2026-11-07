    @php
        // A date range counts as one filter. The count shows on the Filters button and in the panel footer.
        $activeFilterCount = collect(['office', 'type', 'status'])->filter(fn (string $filter): bool => filled($filterValues[$filter] ?? null))->count()
            + (filled($filterValues['from'] ?? null) || filled($filterValues['to'] ?? null) ? 1 : 0);
        $hasAdvancedFilters = $activeFilterCount > 0;
    @endphp
    <div class="dts-search-panel tw:grid tw:grid-cols-1 tw:items-end tw:gap-3 tw:md:grid-cols-[minmax(0,1fr)_auto_auto]">
        <div class="dts-field dts-search-field">
            <label for="registry-search">Search documents</label>
            <div class="dts-input-icon">
                <span class="dts-search-icon" aria-hidden="true"><x-orchid-icon path="bs.search" /></span>
                <input id="registry-search" data-filter="search" type="search" value="{{ $filterValues['search'] }}" placeholder="Tracking no., subject, sender, school or email" autocomplete="off">
            </div>
        </div>
        @php($filtersLabel = $activeFilterCount > 0 ? 'Filters ('.$activeFilterCount.' active)' : 'Filters')
        <button type="button" class="dts-button dts-button-primary dts-icon-only" data-filter-action="apply" aria-label="Search" data-tooltip="Search"><x-orchid-icon path="bs.search" /></button>
        {{-- Icon-only, except that the active-filter badge keeps its place: it is the only sign on this screen that
             the list is narrowed, so it is never traded for the hover text. --}}
        <button type="button" class="dts-button dts-button-secondary @if ($activeFilterCount === 0) dts-icon-only @endif" data-filter-toggle aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}" aria-controls="registry-filter-panel" aria-label="{{ $filtersLabel }}" data-tooltip="{{ $filtersLabel }}"><x-orchid-icon path="bs.funnel" />@if ($activeFilterCount > 0)<span class="dts-filter-count">{{ $activeFilterCount }}<span class="visually-hidden"> active</span></span>@endif</button>
    </div>
    <div id="registry-filter-panel" class="dts-registry-panel" data-filter-panel @unless ($hasAdvancedFilters) hidden @endunless>
        <div class="dts-search-panel-more">
            {{-- One label style for every field and one row height for every box; 3 columns wide, 2 on tablets, 1 on phones. --}}
            <div class="dts-filter-grid">
                <div class="dts-field">
                    <label for="registry-office">Assigned office</label>
                    {{-- dts-searchable, the same control the routing dialogs use, so the list of choices is drawn
                         and styled by the app rather than by the browser. The datalist below stays as its source
                         of options (read by id through data-options); `list` is gone, or the browser would draw
                         its own popup over this one. --}}
                    <dts-searchable>
                        <div class="dts-list-input">
                            <input id="registry-office" data-filter="office" data-searchable data-options="registry-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $filterValues['office'] }}" placeholder="All offices" autocomplete="off">
                            <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                        </div>
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                    <datalist id="registry-offices">
                        @foreach ($officeOptions as $office)
                            <option value="{{ $office }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="dts-field">
                    <label for="registry-type">Document type</label>
                    <dts-searchable>
                        <div class="dts-list-input">
                            <input id="registry-type" data-filter="type" data-searchable data-options="registry-types" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $filterValues['type'] }}" placeholder="All types" autocomplete="off">
                            <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                        </div>
                        <div data-suggestions role="listbox" hidden></div>
                    </dts-searchable>
                    <datalist id="registry-types">
                        @foreach ($typeOptions as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>
                </div>
                @if ($section === 'documents')
                    <div class="dts-field">
                        <label for="registry-status">Status</label>
                        <dts-searchable>
                            <div class="dts-list-input">
                                <input id="registry-status" data-filter="status" data-searchable data-options="registry-statuses" role="combobox" aria-autocomplete="list" aria-expanded="false" value="{{ $filterValues['status'] }}" placeholder="All statuses" autocomplete="off">
                                <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                            </div>
                            <div data-suggestions role="listbox" hidden></div>
                        </dts-searchable>
                        <datalist id="registry-statuses">
                            {{-- Shown names, once each: the two waiting statuses are one "Pending" option (the filter matches both). --}}
                            @foreach (collect(\App\Models\Document::STATUSES)->map(fn (string $status): string => \App\Support\StatusLabel::for($status))->unique() as $status)
                                <option value="{{ $status }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                @endif
                <div class="dts-field dts-date-range" role="group" aria-labelledby="registry-date-label">
                    <span id="registry-date-label" class="dts-field-label">Date received</span>
                    <div class="dts-date-range-fields">
                        <input id="registry-from" data-filter="from" type="date" value="{{ $filterValues['from'] }}" aria-label="Received from">
                        <span aria-hidden="true">–</span>
                        <input id="registry-to" data-filter="to" type="date" value="{{ $filterValues['to'] }}" aria-label="Received to" aria-describedby="registry-error">
                    </div>
                </div>
            </div>
            <div class="dts-filter-footer">
                <p>{{ $activeFilterCount === 0 ? 'No filters applied' : $activeFilterCount.' '.Str::plural('filter', $activeFilterCount).' active' }}</p>
                <div class="dts-filter-footer-actions">
                    {{-- Word only: these two commit the panel, and the icons repeated the funnel already used by
                         the Filters toggle above, which read as a third filter control rather than a verb. --}}
                    <button type="button" class="dts-button dts-reset-filters" data-filter-action="reset">Reset</button>
                    <button type="button" class="dts-button dts-apply-filters" data-filter-action="apply">Apply</button>
                </div>
            </div>
        </div>
        <p id="registry-error" class="dts-validation" role="alert">{{ $filterError }}</p>
    </div>
