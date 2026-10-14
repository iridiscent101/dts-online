<div class="dts-section-heading tw:flex tw:items-center tw:justify-between tw:gap-3">
    <h3 id="document-attachments-heading"><x-orchid-icon path="bs.paperclip" /> Attachments</h3>
    <span class="dts-count">{{ $document->attachments->count() }} / 5</span>
</div>

@if ($canManageAttachments && $document->attachments->count() < 5)
    <div class="dts-attachment-upload">
        <label for="document-attachment-additions" class="dts-attachment-picker">
            <x-orchid-icon path="bs.paperclip" />
            <span data-attachment-selection>Choose files</span>
            <input id="document-attachment-additions" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.xls,.csv" data-attachment-files>
        </label>
        <button type="button" class="dts-button dts-button-primary" data-attachment-add data-url="{{ route('platform.documents.attachments.store', $document) }}">
            <x-orchid-icon path="bs.upload" /> Upload
        </button>
        <small>PDF, image, Word, Excel, or CSV. Up to 10 MB each.</small>
    </div>
@endif

@forelse ($document->attachments as $attachment)
    <div class="dts-stored-attachment @if($canManageAttachments) dts-stored-attachment-manageable @endif">
        <span class="dts-icon"><x-orchid-icon path="bs.file-earmark" /></span>
        <div class="dts-attachment-info">
            <strong>{{ $attachment->original_name }}</strong>
            <small>{{ strtoupper($attachment->extension) }} &middot; {{ Illuminate\Support\Number::fileSize($attachment->size) }}</small>
        </div>
        <div class="dts-attachment-actions">
            <a class="dts-icon-button" href="{{ route('platform.documents.attachments.preview', [$document, $attachment]) }}" target="_blank" rel="noopener" aria-label="Preview {{ $attachment->original_name }}" title="Preview"><x-orchid-icon path="bs.eye" /></a>
            <a class="dts-icon-button" href="{{ route('platform.documents.attachments.download', [$document, $attachment]) }}" aria-label="Download {{ $attachment->original_name }}" title="Download"><x-orchid-icon path="bs.download" /></a>
            @if ($canManageAttachments)
                <label class="dts-icon-button dts-attachment-replace" aria-label="Replace {{ $attachment->original_name }}" title="Replace">
                    <x-orchid-icon path="bs.arrow-repeat" />
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.xls,.csv" data-attachment-replacement data-url="{{ route('platform.documents.attachments.replace', [$document, $attachment]) }}" data-file-name="{{ $attachment->original_name }}">
                </label>
                <button type="button" class="dts-icon-button dts-icon-button-danger" data-attachment-delete data-url="{{ route('platform.documents.attachments.destroy', [$document, $attachment]) }}" data-file-name="{{ $attachment->original_name }}" aria-label="Delete {{ $attachment->original_name }}" title="{{ $document->attachments->count() === 1 ? 'Replace the final attachment instead' : 'Delete' }}" @disabled($document->attachments->count() === 1)><x-orchid-icon path="bs.trash" /></button>
            @endif
        </div>
    </div>
@empty
    <div class="dts-empty dts-empty-compact">
        <span class="dts-empty-icon"><x-orchid-icon path="bs.paperclip" /></span>
        <h4>No attachments</h4>
        <p>No files were uploaded with this document.</p>
    </div>
@endforelse
