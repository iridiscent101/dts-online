{{-- Release: the person taking the document signs, either here (resources/js/signature-pad.js) or on the kiosk laptop
     outside the Records Unit ("Send to kiosk", KioskSigningRequestController), whose signature then fills this pad.
     Optional for now, like the recipient's name; dts-workflow-routing enables it only for Release. The PNG goes in
     workflow[recipient_signature]. --}}
<div class="dts-field dts-field-wide dts-release-signature" data-release-signature data-kiosk-url="{{ route('platform.kiosk-signing.store', $document) }}" hidden>
    <div class="dts-release-signature-head">
        <span class="dts-release-signature-label" id="{{ $id }}-label">Recipient's signature <span class="dts-optional">Optional</span></span>
        <button type="button" class="dts-button dts-button-secondary" data-kiosk-send><x-orchid-icon path="bs.display" /> Send to kiosk</button>
    </div>
    <div class="dts-kiosk-status" data-kiosk-status role="status" aria-live="polite" hidden>
        <span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-kiosk-spinner aria-hidden="true"></span>
        <span data-kiosk-message></span>
        <button type="button" class="dts-button dts-button-secondary" data-kiosk-cancel hidden>Cancel</button>
    </div>
    <dts-signature-pad data-required-message="Ask the recipient to sign, here or on the kiosk, before releasing.">
        <div class="dts-signature-box">
            <canvas aria-labelledby="{{ $id }}-label" aria-describedby="{{ $id }}-hint" role="img"></canvas>
            <span class="dts-signature-line" aria-hidden="true">Sign above this line</span>
            <input class="dts-signature-value" name="workflow[recipient_signature]" data-signature-value tabindex="-1" aria-hidden="true" disabled>
        </div>
        <div class="dts-signature-footer">
            <p class="dts-signature-hint" id="{{ $id }}-hint" data-signature-hint>Collect the signature here or select Send to kiosk.</p>
            <button type="button" class="dts-button dts-button-secondary" data-signature-clear disabled>Clear</button>
        </div>
    </dts-signature-pad>
</div>
