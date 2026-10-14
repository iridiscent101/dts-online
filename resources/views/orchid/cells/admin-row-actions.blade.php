<div class="dts-row-actions">
    @if ($editAction)
        {{ $editAction }}
    @endif
    @if ($deleteAction)
        {{ $deleteAction }}
    @endif
    @if (! $editAction && ! $deleteAction)
        <span class="dts-row-actions-locked" title="Only an administrator can change this"><x-orchid-icon path="bs.lock" /> Admin only</span>
    @endif
</div>
