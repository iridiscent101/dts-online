    @php
        // A date range counts as one filter. The count shows on the Filters button and in the panel footer.
        $activeFilterCount = collect(['office', 'type', 'status', 'due', 'priority'])->filter(fn (string $filter): bool => filled($filterValues[$filter] ?? null))->count()
            + (filled($filterValues['from'] ?? null) || filled($filterValues['to'] ?? null) ? 1 : 0);
        $hasAdvancedFilters = $activeFilterCount > 0;
    @endphp
    <div class="dts-search-panel tw:grid tw:grid-cols-1 tw:items-end tw:gap-3 tw:md:grid-cols-[minmax(0,1fr)_auto_auto]">
        <div class="dts-field dts-search-field">
            <label for="registry-search">Search documents</label>
            <div class="dts-input-icon">
                <span class="dts-search-icon" aria-hidden="true"><x-orchid-icon path="bs.search" /></span>
                <input id="registry-search" data-filter="search" type="search" value="{{ $filterValues['search'] }}" placeholder="Tracking no., subject, sender or school" autocomplete="off">
            </div>
        </div>
        <button type="button" class="dts-button dts-button-primary" data-filter-action="apply">Search</button>
        <button type="button" class="dts-button dts-button-secondary" data-filter-toggle aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}" aria-controls="registry-filter-panel"><x-orchid-icon path="bs.funnel" /> Filters @if ($activeFilterCount > 0)<span class="dts-filter-count">{{ $activeFilterCount }}<span class="visually-hidden"> active</span></span>@endif</button>
    </div>
    <div id="registry-filter-panel" class="dts-registry-panel" data-filter-panel @unless ($hasAdvancedFilters) hidden @endunless>
        <div class="dts-search-panel-more">
            {{-- One label style for every field and one row height for every box; 3 columns wide, 2 on tablets, 1 on phones. --}}
            <div class="dts-filter-grid">
                <div class="dts-field">
                    <label for="registry-office">Assigned office</label>
                    <div class="dts-list-input">
                        <input id="registry-office" data-filter="office" list="registry-offices" value="{{ $filterValues['office'] }}" placeholder="All offices" autocomplete="off">
                        <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                    </div>
                    <datalist id="registry-offices">
                        @foreach ($officeOptions as $office)
                            <option value="{{ $office }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="dts-field">
                    <label for="registry-type">Document type</label>
                    <div class="dts-list-input">
                        <input id="registry-type" data-filter="type" list="registry-types" value="{{ $filterValues['type'] }}" placeholder="All types" autocomplete="off">
                        <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                    </div>
                    <datalist id="registry-types">
                        @foreach ($typeOptions as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>
                </div>
                @if ($section === 'documents')
                    <div class="dts-field">
                        <label for="registry-status">Status</label>
                        <div class="dts-list-input">
                            <input id="registry-status" data-filter="status" list="registry-statuses" value="{{ $filterValues['status'] }}" placeholder="All statuses" autocomplete="off">
                            <x-orchid-icon path="bs.chevron-down" class="dts-list-input-icon" aria-hidden="true" />
                        </div>
                        <datalist id="registry-statuses">
                            @foreach (\App\Models\Document::STATUSES as $status)
                                <option value="{{ $status }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                @endif
                <div class="dts-field">
                    <label for="registry-due">Due</label>
                    <select id="registry-due" data-filter="due">
                        <option value="">Any due date</option>
                        <option value="overdue" @selected($filterValues['due'] === 'overdue')>Overdue</option>
                        <option value="soon" @selected($filterValues['due'] === 'soon')>Due within 2 days</option>
                    </select>
                </div>
                <div class="dts-field">
                    <label for="registry-priority">Priority</label>
                    <select id="registry-priority" data-filter="priority">
                        <option value="">All priorities</option>
                        @foreach (array_keys(\App\Models\Document::PRIORITIES) as $priority)
                            <option value="{{ $priority }}" @selected($filterValues['priority'] === $priority)>{{ $priority }}</option>
                        @endforeach
                    </select>
                </div>
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
                    <button type="button" class="dts-button dts-reset-filters" data-filter-action="reset"><x-orchid-icon path="bs.arrow-counterclockwise" /> Reset</button>
                    <button type="button" class="dts-button dts-apply-filters" data-filter-action="apply"><x-orchid-icon path="bs.funnel" /> Apply</button>
                </div>
            </div>
        </div>
        <p id="registry-error" class="dts-validation" role="alert">{{ $filterError }}</p>
    </div>
