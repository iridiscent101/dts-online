<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyRecoveryEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class RecoveryEmailController extends Controller
{
    public static function sendVerification(User $user): void
    {
        $rateLimitKey = 'recovery-verification:'.$user->id.':'.hash('sha256', $user->notification_email);
        if (! RateLimiter::attempt($rateLimitKey, 1, fn () => true, 60)) {
            return;
        }
        $token = Str::random(64);
        $user->forceFill(['recovery_email_verification_token' => hash('sha256', $token)])->save();
        try {
            $user->notifyNow(new VerifyRecoveryEmail($token));
        } catch (Throwable $exception) {
            RateLimiter::clear($rateLimitKey);
            report($exception);
        }
    }

    public function verify(User $user, string $token): View
    {
        abort_if($user->isDeactivated() || ! filled($user->notification_email) || ! is_string($user->recovery_email_verification_token) || ! hash_equals($user->recovery_email_verification_token, hash('sha256', $token)), 403);
        $user->forceFill(['notification_email_verified_at' => now(), 'recovery_email_verification_token' => null])->save();

        return view('platform::auth.recovery-email-verified');
    }
}
