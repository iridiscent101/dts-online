{{-- My Account when RequirePasswordChange sent the person here: why, and what the new password needs. --}}
<div class="dts-workflow-notice dts-password-change-required" role="alert">
    <span class="dts-icon"><x-orchid-icon path="bs.shield-lock" /></span>
    <div>
        <strong>Please set a new password</strong>
        @switch(request()->user()?->passwordChangeReason())
            @case('temporary')
                <p>You signed in with a temporary password. Type it as your current password below, then choose a new password of your own to continue.</p>
                @break
            @case('administrator')
                <p>An administrator set this password for you. Type it as your current password below, then choose a new password that only you know to continue.</p>
                @break
            @default
                <p>The password rules have changed, and your current password does not meet them. Set a new one below to continue using the system.</p>
        @endswitch
        <p>{{ \App\Models\User::PASSWORD_HINT }}</p>
    </div>
</div>
