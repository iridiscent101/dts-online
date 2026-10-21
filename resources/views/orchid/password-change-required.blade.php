{{-- My Account when RequirePasswordChange sent the person here: why, and what the new password needs. --}}
<div class="dts-workflow-notice dts-password-change-required" role="alert">
    <span class="dts-icon"><x-orchid-icon path="bs.shield-lock" /></span>
    <div>
        <strong>Please set a new password</strong>
        <p>The password rules have changed, and your current password does not meet them. Set a new one below to continue using the system.</p>
        <p>{{ \App\Models\User::PASSWORD_HINT }}</p>
    </div>
</div>
