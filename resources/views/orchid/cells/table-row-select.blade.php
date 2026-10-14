<input
    type="checkbox"
    class="dts-table-checkbox"
    name="selected[]"
    value="{{ $value }}"
    data-table-row-select
    aria-label="{{ $label }} {{ $value }}"
    @disabled($disabled ?? false)
>
