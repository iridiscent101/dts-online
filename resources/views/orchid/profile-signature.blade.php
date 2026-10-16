{{-- Signature printed under "By" on the QR labels of documents you process. The page sits inside Orchid's screen
     form, so the picture is sent by dts.js (no nested form, no name on the file input). --}}
<section class="dts-profile-signature" data-profile-signature data-upload-url="{{ route('platform.profile.signature.store') }}" aria-label="Signature on labels">
    <div class="dts-profile-signature-preview" data-profile-signature-preview>
        @if ($user->signature_path)
            <img src="{{ route('platform.profile.signature.show') }}" alt="Your saved signature" data-profile-signature-image>
        @else
            <span class="dts-muted" data-profile-signature-empty>No signature yet</span>
        @endif
    </div>
    <label for="profile-signature-file">Choose a picture of your signature</label>
    <input id="profile-signature-file" type="file" accept="image/png,image/jpeg,image/webp" data-profile-signature-file aria-describedby="profile-signature-hint">
    <p id="profile-signature-hint" class="dts-field-hint">PNG, JPG or WebP. A large picture is made smaller automatically. Dark ink on a white or clear background prints best.</p>
    <p class="dts-workspace-form-errors" data-profile-signature-errors role="alert" hidden></p>
    <p class="dts-profile-signature-saved" data-profile-signature-status role="status"></p>
    <div><button type="button" class="dts-button dts-button-primary" data-profile-signature-save disabled><span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-workspace-button-spinner aria-hidden="true" hidden></span> Save signature</button></div>
</section>
