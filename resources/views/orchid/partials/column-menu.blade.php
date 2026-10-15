{{-- "Columns" menu above a table; dts.js (ColumnMenu) adds one checkbox per column the table lets users hide. --}}
<dts-column-menu>
    <details class="dts-column-menu">
        <summary class="dts-button dts-icon-only" aria-label="Show or hide columns" data-tooltip="Columns"><x-orchid-icon path="bs.layout-three-columns" /><span class="visually-hidden">Columns <span data-visible-column-count></span></span></summary>
        <div class="dts-column-options"><strong>Show or hide columns</strong></div>
    </details>
</dts-column-menu>
