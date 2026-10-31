{{-- Edit role: a permission group's title and how many of its permissions are on (dts.js updates the count). --}}
<div class="dts-permission-group-heading" data-permission-group-heading="{{ $groupKey }}">
    <h4>{{ $groupTitle }}</h4>
    <span class="dts-permission-group-count" data-permission-group-count aria-live="polite"><strong>{{ $groupActive }}</strong> of {{ $groupTotal }} on</span>
</div>
