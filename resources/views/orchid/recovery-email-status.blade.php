@if (filled($user->notification_email))
    @php($verified = filled($user->notification_email_verified_at))
    <div class="dts-recovery-status" role="status">
        <span @class(['dts-email-alerts-status', 'is-active' => $verified, 'is-pending' => ! $verified])>
            <x-orchid-icon :path="$verified ? 'bs.check-circle-fill' : 'bs.hourglass-split'" aria-hidden="true"/>
            Recovery email: {{ $verified ? 'Verified' : 'Not verified' }}
        </span>
        @unless ($verified)
            <p class="dts-recovery-status-note">Open the link in the email we sent, or use <strong>Send recovery email verification</strong> above to get a new one.</p>
        @endunless
    </div>
@endif
