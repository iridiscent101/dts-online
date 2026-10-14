@php
    $frameId = match (request()->route()?->getName()) {
        'platform.systems.users' => 'admin-users-table',
        'platform.systems.roles' => 'admin-roles-table',
        'platform.systems.offices' => 'admin-offices-table',
        'platform.systems.schools' => 'admin-schools-table',
        default => 'admin-table',
    };
    $bulkDelete = match ($frameId) {
        'admin-users-table' => ['user', route('platform.systems.users').'/bulkRemove'],
        'admin-roles-table' => ['role', route('platform.systems.roles').'/bulkRemove'],
        'admin-offices-table' => ['office', route('platform.systems.offices').'/bulkRemove'],
        'admin-schools-table' => ['school', route('platform.systems.schools').'/bulkRemove'],
        default => null,
    };
@endphp

{{-- Same toolbar as the document registry: columns and rows per page on the right; "Delete selected" appears in the
     selection bar once rows are ticked (see updateTableSelection in dts.js). --}}
<turbo-frame id="{{ $frameId }}" data-turbo-action="advance" @if ($bulkDelete !== null) data-admin-bulk-delete-kind="{{ $bulkDelete[0] }}" data-admin-bulk-delete-url="{{ $bulkDelete[1] }}" @endif>
    {!! $filters !!}
    <div class="dts-registry-table-toolbar">
        @include('orchid.partials.column-menu')
        {!! $pageSize !!}
    </div>
    {!! $table !!}
</turbo-frame>
