@if (filled($user->notification_email))
    <p class="text-muted">Recovery email: {{ $user->notification_email_verified_at ? 'Verified' : 'Not verified. Check your email or send another verification link.' }}</p>
@endif
