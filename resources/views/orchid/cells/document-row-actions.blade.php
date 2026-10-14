<div class="dts-row-actions">
    @if ($canEdit)
        <a class="btn dts-row-action dts-row-action-edit" href="{{ $editUrl }}" data-workspace-url="{{ $workspaceUrl }}" data-workspace-edit-open><x-orchid-icon path="bs.pencil" /> Edit</a>
    @endif
    @if ($canDelete)
        {{ $deleteAction }}
    @endif
</div>
