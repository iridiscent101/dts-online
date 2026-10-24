{{-- "Add remark" for a received, active office route (dts.js WorkflowRouting shows it when the acting office may
     forward). It adds a note to that office's history and changes no action or status (DocumentRemarkController).
     Not a form: the document page sits inside Orchid's screen form, so dts.js sends it. --}}
<div class="dts-office-remark" data-office-remark data-remark-url="{{ route('platform.documents.remarks.store', $document) }}" hidden>
    <button type="button" class="dts-button dts-button-secondary" data-office-remark-toggle aria-expanded="false" aria-controls="{{ $id }}-panel">
        <x-orchid-icon path="bs.chat-left-text" /> Add remark
    </button>
    <div class="dts-office-remark-panel" id="{{ $id }}-panel" data-office-remark-panel hidden>
        <label for="{{ $id }}-text">Remark for <span data-office-remark-office></span></label>
        <textarea id="{{ $id }}-text" data-office-remark-text rows="3" maxlength="1000" aria-describedby="{{ $id }}-hint" autocomplete="off"></textarea>
        <p id="{{ $id }}-hint" class="dts-field-hint">Added to this office's history and shown on the public tracking page, so leave out phone numbers, addresses, ID numbers, and health or money details. It does not change the document's action or status.</p>
        <p class="dts-workspace-form-errors" data-office-remark-errors role="alert" hidden></p>
        <div class="dts-office-remark-actions">
            <button type="button" class="dts-button" data-office-remark-cancel>Cancel</button>
            <button type="button" class="dts-button dts-button-primary" data-office-remark-save><span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-workspace-button-spinner aria-hidden="true" hidden></span> Save remark</button>
        </div>
    </div>
</div>
