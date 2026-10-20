{{-- Optional email alerts (users.notification_email), separate from the sign-in email. Off means the address is
     submitted empty, which clears it and leaves in-app notifications only. Toggle handled in resources/js/dts.js. --}}
@php($enabled = filled($value))
<div class="dts-email-alerts" data-email-alerts>
    <div class="form-check form-switch">
        <input type="checkbox" class="form-check-input" role="switch" id="{{ $id }}-toggle" data-email-alerts-toggle
               aria-controls="{{ $id }}-field" @checked($enabled)>
        <label class="form-check-label" for="{{ $id }}-toggle">Receive email notifications</label>
    </div>
    <div class="dts-email-alerts-field mt-3" id="{{ $id }}-field" data-email-alerts-field @unless($enabled) hidden @endunless>
        <label class="form-label" for="{{ $id }}">Gmail address for alerts</label>
        <input type="email" class="form-control @error('user.notification_email') is-invalid @enderror" id="{{ $id }}"
               name="{{ $name }}" value="{{ $value }}" maxlength="255" autocomplete="email" placeholder="name@gmail.com"
               aria-describedby="{{ $id }}-help" @required($enabled)>
        @error('user.notification_email')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        {{-- Status of the saved address only; hidden while a different address is typed (dts.js). --}}
        @if (filled($savedAddress ?? null))
            <p class="dts-email-alerts-status {{ $confirmed ? 'is-active' : 'is-pending' }}" data-email-alerts-status
               data-saved-address="{{ $savedAddress }}" @if ($value !== $savedAddress) hidden @endif>
                <x-orchid-icon path="{{ $confirmed ? 'bs.check-circle' : 'bs.envelope-exclamation' }}" />
                {{ $confirmed ? 'Email alerts active' : 'Check your inbox to confirm this address' }}
            </p>
        @endif
        <small class="form-text text-muted" id="{{ $id }}-help">New document assignments are emailed here once the address is confirmed. Signing in still uses the email above.</small>
    </div>
</div>
