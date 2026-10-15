{{-- Icon-only copy button with hover text. The hidden label is read by screen readers and turns into "Copied". --}}
<button type="button" class="dts-copy-button" data-copy-text="{{ $text }}" data-tooltip="Copy" aria-label="Copy tracking number {{ $text }}">
    <span class="dts-copy-icon" data-copy-icon="copy" aria-hidden="true"><x-orchid-icon path="bs.copy" /></span>
    <span class="dts-copy-icon" data-copy-icon="done" aria-hidden="true"><x-orchid-icon path="bs.check2" /></span>
    <span data-copy-label aria-live="polite">Copy</span>
</button>
