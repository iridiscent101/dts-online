<div class="p-3">
    @if ($user->password_recovery_totp_secret)
        <p class="mb-3">Your authenticator is enabled. A forgotten-password reset needs both the emailed link and a six-digit code.</p>
        <p class="mb-3">To turn it off, enter your current password and a code from your authenticator.</p>
    @else
        <p class="mb-3">Scan this code with an authenticator app, or enter the setup key manually. Confirm with your current password and a six-digit code. This only applies to forgotten-password resets.</p>
        <img src="{{ $totpQrCode }}" alt="Authenticator setup QR code" width="180" height="180" class="d-block mb-2">
        <p class="mb-3">Setup key: <code>{{ $totpSetupSecret }}</code></p>
    @endif

    <div class="mb-3">
        <label class="form-label" for="totp_current_password">Current password</label>
        <input id="totp_current_password" name="totp_current_password" type="password" class="form-control" autocomplete="current-password">
        @error('totp_current_password') <p class="text-danger mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="mb-2">
        <label class="form-label" for="profile_totp_code">Six-digit authenticator code</label>
        <input id="profile_totp_code" name="totp_code" type="text" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
        @error('totp_code') <p class="text-danger mt-1">{{ $message }}</p> @enderror
    </div>
</div>
