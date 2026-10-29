<input
    type="checkbox"
    class="dts-table-checkbox"
    name="selected[]"
    value="{{ $value }}"
    data-table-row-select
    @if ($deactivated ?? false) data-row-deactivated @endif
    aria-label="{{ $label }} {{ $value }}"
    @disabled($disabled ?? false)
>
