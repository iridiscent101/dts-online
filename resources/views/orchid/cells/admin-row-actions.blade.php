{{-- Fixed slots: Edit first, then Delete (or Deactivate / Reactivate). A missing button leaves an empty slot of the same
     size, so every row's buttons line up in the same columns. --}}
<div class="dts-row-actions">
    @if (! $editAction && ! $deleteAction)
        <span class="dts-row-actions-locked" title="Only an administrator can change this"><x-orchid-icon path="bs.lock" /> Admin only</span>
    @else
        @if ($editAction)
            {{ $editAction }}
        @else
            <span class="dts-row-action-slot" aria-hidden="true"></span>
        @endif
        @if ($deleteAction)
            {{ $deleteAction }}
        @else
            <span class="dts-row-action-slot" aria-hidden="true"></span>
        @endif
    @endif
</div>
