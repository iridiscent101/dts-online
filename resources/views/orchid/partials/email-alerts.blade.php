@php($enabled = filled($value))
<div class="dts-email-alerts" data-email-alerts>
    <div class="form-check form-switch">
        <input type="checkbox" class="form-check-input" role="switch" id="{{ $id }}-toggle" data-email-alerts-toggle
               aria-controls="{{ $id }}-field" @checked($enabled)>
        <label class="form-check-label" for="{{ $id }}-toggle">Add a recovery email for forgotten passwords</label>
    </div>
    <div class="dts-email-alerts-field mt-3" id="{{ $id }}-field" data-email-alerts-field @unless($enabled) hidden @endunless>
        <label class="form-label" for="{{ $id }}">Recovery email (Gmail)</label>
        <input type="email" class="form-control @error('user.notification_email') is-invalid @enderror" id="{{ $id }}"
               name="{{ $name }}" value="{{ $value }}" maxlength="255" autocomplete="email" placeholder="name@gmail.com"
               aria-describedby="{{ $id }}-help" @required($enabled)>
        @error('user.notification_email')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        <small class="form-text text-muted" id="{{ $id }}-help">Verify this address using the email we send before using it for password resets. Check your Spam folder and mark it "Report not spam" if needed. Signing in still uses the email above.</small>
    </div>
</div>
