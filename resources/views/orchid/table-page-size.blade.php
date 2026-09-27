@php
    $pageSizes = [10, 25, 50, 100];
    $currentPageSize = request()->integer('per_page', 10);
    $currentPageSize = in_array($currentPageSize, $pageSizes, true) ? $currentPageSize : 10;
@endphp

<div class="dts-page-size-toolbar">
    <label for="table-page-size">Rows per page</label>
    <select id="table-page-size" name="per_page" data-page-size>
        @foreach ($pageSizes as $pageSize)
            <option value="{{ $pageSize }}" @selected($currentPageSize === $pageSize)>{{ $pageSize }}</option>
        @endforeach
    </select>
</div>
