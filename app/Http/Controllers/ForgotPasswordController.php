<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

use function Illuminate\Support\defer;

class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If that account has a verified recovery email, a password reset link was sent to it. Check your Spam folder if it is not in your Inbox.';

    public function show(): View
    {
        return view('platform::auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        $work = function () use ($email): void {
            $user = User::query()->where('email', $email)->first();
            if (! $user || $user->isDeactivated() || ! $user->canChangeOwnPassword() || ! $user->notification_email_verified_at || ! filled($user->notification_email)) {
                return;
            }
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (Throwable $exception) {
                Password::deleteToken($user);
                report($exception);
            }
        };
        if (config('queue.default') === 'sync') {
            (new Timebox)->call(fn () => $work(), (int) round((float) config('dts.forgot_password_answer_seconds') * 1_000_000));
        } else {
            defer($work);
        }

        return redirect()->route('password.forgot')->with('status', self::SENT_MESSAGE);
    }
}
