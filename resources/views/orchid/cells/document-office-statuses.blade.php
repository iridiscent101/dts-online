@php
    $officeStatuses = $document->officeRouteStatuses();
@endphp

<div class="dts-office-status-list">
    @foreach ($officeStatuses as $assignment)
        <a class="dts-office-status-item" href="{{ route('platform.documents.view', ['document' => $document, 'source_office' => $assignment['office']]).'#document-routing-heading' }}" data-workspace-url="{{ route('platform.documents.workspace', $document) }}" data-workspace-office="{{ $assignment['office'] }}" data-turbo-frame="_top" data-workflow-office-link="{{ $assignment['office'] }}" aria-label="View {{ $assignment['office'] }} workflow, {{ \App\Support\StatusLabel::for($assignment['status']) }}">
            <span class="dts-office-status-name">{{ $assignment['office'] }}</span>
            <span class="dts-record-status" data-status="{{ str($assignment['status'])->lower()->replace(' ', '-') }}" {{ \App\Support\StatusLabel::titleAttribute($assignment['status']) }}>{{ \App\Support\StatusLabel::for($assignment['status']) }}</span>
        </a>
    @endforeach
</div>
